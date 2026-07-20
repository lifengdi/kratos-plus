<?php

/**
 * 每日心情灯（Mood Log）
 *
 * 每天一格心情（1-5 档）+ 一句话，年终汇成一张情绪热力图。
 *
 * 短码：
 *   [mood_log]        情绪热力图（含年份切换、统计），如登录且可管理选项，则顶部附带今日录入卡
 *   [mood_log_input]  仅今日录入卡（仅站长可见，其他用户返回空）
 *
 * 数据表：{prefix}kratos_mood_log
 *   date DATE PRIMARY KEY, mood TINYINT (1-5), note VARCHAR(140), created_at DATETIME
 *
 * 配色：完全复用 post-heatmap 的 --khs-* 变量映射（.kratos-heatmap.is-mood），
 * 皮肤零改动，5 档色阶由 color-mix(--khs-accent) 生成。
 *
 * @author Dylan Li
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

/**
 * 表名。
 */
function kratos_mood_table()
{
    global $wpdb;
    return $wpdb->prefix . 'kratos_mood_log';
}

/**
 * 建表（首次访问后台时自动建）。
 */
function kratos_mood_install()
{
    if (get_option('kratos_mood_db_version') === '1.0') return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = kratos_mood_table();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        date DATE NOT NULL,
        mood TINYINT UNSIGNED NOT NULL,
        note VARCHAR(140) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (date)
    ) $charset;";
    dbDelta($sql);
    update_option('kratos_mood_db_version', '1.0');
}
add_action('admin_init', 'kratos_mood_install');
add_action('after_switch_theme', 'kratos_mood_install');

/**
 * 默认心情标签 emoji + 文案。
 */
function kratos_mood_labels()
{
    $defaults = array(
        1 => array('emoji' => '😢', 'label' => __('低落', 'kratos')),
        2 => array('emoji' => '😐', 'label' => __('平淡', 'kratos')),
        3 => array('emoji' => '🙂', 'label' => __('尚可', 'kratos')),
        4 => array('emoji' => '😊', 'label' => __('愉悦', 'kratos')),
        5 => array('emoji' => '🤩', 'label' => __('高光', 'kratos')),
    );
    return apply_filters('kratos_mood_labels', $defaults);
}

/**
 * 当前用户是否可录入心情（仅站长）。
 */
function kratos_mood_can_edit()
{
    return is_user_logged_in() && current_user_can('manage_options');
}

/**
 * 一句话是否对访客公开。
 */
function kratos_mood_notes_public()
{
    return (bool) kratos_option('mood_log_public_notes', true);
}

/**
 * 前端资源入队（仅短码所在页 或 时间轴模板需要时）。
 */
function kratos_mood_enqueue_assets()
{
    if (!kratos_option('mood_log_enabled', true)) return;
    global $post;
    $need = false;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'mood_log') || has_shortcode($post->post_content, 'mood_log_input'))) {
        $need = true;
    }
    if (!$need) return;

    wp_register_script('kratos-mood-log', false, array('jquery'), THEME_VERSION, true);
    wp_enqueue_script('kratos-mood-log');
    wp_localize_script('kratos-mood-log', 'kratosMoodLog', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('kratos_mood_save'),
        'canEdit' => kratos_mood_can_edit(),
    ));
    wp_add_inline_script('kratos-mood-log', kratos_mood_js());
    wp_register_style('kratos-mood-log', false, array(), THEME_VERSION);
    wp_enqueue_style('kratos-mood-log');
    wp_add_inline_style('kratos-mood-log', kratos_mood_css());
}
add_action('wp_enqueue_scripts', 'kratos_mood_enqueue_assets');

/**
 * 查询指定范围的心情数据。
 * $year 为空时取最近 $time_range 天。
 */
function kratos_mood_get_data($year = null, $time_range = 365)
{
    global $wpdb;
    $table = kratos_mood_table();

    if ($year) {
        $start = "$year-01-01";
        $end   = "$year-12-31";
        $where = $wpdb->prepare("date BETWEEN %s AND %s", $start, $end);
        $total_days = (strtotime($end) - strtotime($start)) / 86400 + 1;
    } else {
        $start = date('Y-m-d', strtotime("-$time_range days"));
        $where = $wpdb->prepare("date >= %s", $start);
        $total_days = $time_range;
    }

    $rows = $wpdb->get_results("SELECT date, mood, note FROM $table WHERE $where ORDER BY date ASC", ARRAY_A);

    $data = array();
    $mood_dist = array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0);
    $mood_sum = 0;
    $count = 0;
    $show_note = kratos_mood_notes_public();
    $dates = array();

    foreach ($rows as $r) {
        $m = (int) $r['mood'];
        if ($m < 1 || $m > 5) continue;
        $data[$r['date']] = array(
            'mood' => $m,
            'note' => $show_note ? (string) $r['note'] : '',
        );
        $mood_dist[$m]++;
        $mood_sum += $m;
        $count++;
        $dates[] = $r['date'];
    }

    // 连续打卡（含今天）
    $streak = 0;
    $today = date('Y-m-d');
    $cursor = $today;
    while (isset($data[$cursor])) {
        $streak++;
        $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));
    }

    // 最长连续
    $max_streak = 0;
    if ($dates) {
        sort($dates);
        $cur = 1;
        $max_streak = 1;
        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $diff = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
            if ($diff == 1) {
                $cur++;
                if ($cur > $max_streak) $max_streak = $cur;
            } else {
                $cur = 1;
            }
        }
    }

    $avg = $count > 0 ? round($mood_sum / $count, 2) : 0;
    $dist = array();
    foreach ($mood_dist as $lv => $c) {
        $dist[$lv] = array(
            'count'   => $c,
            'percent' => $count > 0 ? round($c / $count * 100, 1) : 0,
        );
    }

    return array(
        'raw_data' => $data,
        'stats'    => array(
            'total'        => $count,
            'total_days'   => (int) $total_days,
            'streak'       => $streak,
            'max_streak'   => $max_streak,
            'avg_mood'     => $avg,
            'distribution' => $dist,
        ),
    );
}

/**
 * 主短码 [mood_log]。
 */
function kratos_mood_shortcode($atts)
{
    if (!kratos_option('mood_log_enabled', true)) return '';

    $atts = shortcode_atts(array(
        'year'       => '',
        'time_range' => (int) kratos_option('mood_log_sc_time_range', 365),
        'title'      => (string) kratos_option('mood_log_sc_title', __('情绪热力图', 'kratos')),
        'width'      => '100%',
    ), $atts, 'mood_log');

    $selected_year = $atts['year'] ? absint($atts['year']) : null;
    $time_range    = max(30, absint($atts['time_range']));
    $title         = sanitize_text_field($atts['title']);
    $width         = esc_attr($atts['width']);
    $canvas_id     = 'kml-' . uniqid();

    global $wpdb;
    $table = kratos_mood_table();
    $earliest = $wpdb->get_var("SELECT YEAR(MIN(date)) FROM $table");
    $current_year = (int) date('Y');
    $earliest = $earliest ? absint($earliest) : $current_year;
    $years = range($earliest, $current_year);
    rsort($years);
    $years_max = (int) kratos_option('mood_log_years_max', 5);
    if ($years_max > 0 && count($years) > $years_max) {
        $years = array_slice($years, 0, $years_max);
    }

    $data = kratos_mood_get_data($selected_year, $time_range);
    $years_json  = wp_json_encode(array_map('intval', $years));
    $labels_json = wp_json_encode(kratos_mood_labels());

    ob_start(); ?>
    <div class="kratos-heatmap is-mood"
         data-time-range="<?php echo esc_attr($time_range); ?>"
         data-years="<?php echo esc_attr($years_json); ?>"
         data-labels="<?php echo esc_attr($labels_json); ?>"
         style="width: <?php echo $width; ?>; max-width: 100%;">

        <?php if ($title !== '') { ?>
        <div class="kph-header kr-hd">
            <span class="kph-title-icon kr-ico" aria-hidden="true">🌈</span>
            <h3 class="kph-title kr-hd-title"><?php echo esc_html($title); ?></h3>
        </div>
        <?php } ?>

        <div class="kph-body kr-body">
            <div id="<?php echo esc_attr($canvas_id); ?>" class="kph-canvas kml-canvas"></div>
            <script type="application/json" class="kml-data-<?php echo esc_attr($canvas_id); ?>">
                <?php echo wp_json_encode(array(
                    'data'       => $data['raw_data'],
                    'stats'      => $data['stats'],
                    'year'       => $selected_year,
                    'time_range' => $time_range,
                )); ?>
            </script>
        </div>
    </div>
    <?php return ob_get_clean();
}
add_shortcode('mood_log', 'kratos_mood_shortcode');

/**
 * 仅录入卡短码 [mood_log_input]。
 */
function kratos_mood_input_shortcode()
{
    if (!kratos_option('mood_log_enabled', true)) return '';
    if (!kratos_mood_can_edit()) return '';
    return '<div class="kratos-heatmap is-mood is-input-only">' . kratos_mood_render_input_card() . '</div>';
}
add_shortcode('mood_log_input', 'kratos_mood_input_shortcode');

/**
 * 今日录入卡 HTML。
 */
function kratos_mood_render_input_card()
{
    global $wpdb;
    $table = kratos_mood_table();
    $today = date('Y-m-d');
    $row = $wpdb->get_row($wpdb->prepare("SELECT mood, note FROM $table WHERE date = %s", $today), ARRAY_A);
    $cur_mood = $row ? (int) $row['mood'] : 0;
    $cur_note = $row ? (string) $row['note'] : '';
    $labels = kratos_mood_labels();

    $weekdays = array(__('周日', 'kratos'), __('周一', 'kratos'), __('周二', 'kratos'), __('周三', 'kratos'), __('周四', 'kratos'), __('周五', 'kratos'), __('周六', 'kratos'));
    $today_h = sprintf(
        __('%1$s 年 %2$s 月 %3$s 日 · %4$s', 'kratos'),
        date('Y'), date('m'), date('d'), $weekdays[(int) date('w')]
    );

    ob_start(); ?>
    <div class="kml-input-card">
        <div class="kml-input-head">
            <h3><?php _e('今天心情如何？', 'kratos'); ?></h3>
            <span class="today"><?php echo esc_html($today_h); ?></span>
        </div>
        <div class="kml-mood-picker">
            <?php foreach ($labels as $lv => $meta) { ?>
                <button type="button" class="kml-mood-btn<?php echo $cur_mood === $lv ? ' is-active' : ''; ?>" data-mood="<?php echo (int) $lv; ?>">
                    <em><?php echo esc_html($meta['emoji']); ?></em>
                    <span><?php echo esc_html($meta['label']); ?></span>
                </button>
            <?php } ?>
        </div>
        <div class="kml-note-wrap">
            <textarea class="kml-note" maxlength="140" placeholder="<?php esc_attr_e('一句话，记录此刻的感受……', 'kratos'); ?>"><?php echo esc_textarea($cur_note); ?></textarea>
            <span class="kml-counter"><?php echo mb_strlen($cur_note); ?> / 140</span>
        </div>
        <div class="kml-actions">
            <span class="kml-msg" aria-live="polite"></span>
            <button type="button" class="kml-btn-save kr-btn"><?php _e('保存今天的心情', 'kratos'); ?></button>
        </div>
    </div>
    <?php return ob_get_clean();
}

/**
 * 后台菜单：仪表盘 → 心情灯。
 */
function kratos_mood_admin_menu()
{
    if (!kratos_option('mood_log_enabled', true)) return;
    add_menu_page(
        __('每日心情灯', 'kratos'),
        __('心情灯', 'kratos'),
        'manage_options',
        'kratos-mood-log',
        'kratos_mood_admin_page',
        'dashicons-smiley',
        26
    );
}
add_action('admin_menu', 'kratos_mood_admin_menu');

/**
 * 后台管理页：录入 + 最近记录列表。
 */
function kratos_mood_admin_page()
{
    if (!kratos_mood_can_edit()) wp_die(__('无权限访问', 'kratos'));

    global $wpdb;
    $table = kratos_mood_table();

    // 处理删除
    if (!empty($_POST['kratos_mood_delete']) && !empty($_POST['date'])
        && check_admin_referer('kratos_mood_admin')) {
        $wpdb->delete($table, array('date' => sanitize_text_field($_POST['date'])), array('%s'));
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('已删除该天记录', 'kratos') . '</p></div>';
    }

    $labels  = kratos_mood_labels();
    $recent  = $wpdb->get_results("SELECT date, mood, note, created_at FROM $table ORDER BY date DESC LIMIT 60", ARRAY_A);
    $total   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $delete_nonce = wp_create_nonce('kratos_mood_admin');

    // 确保前端资源在后台页也加载（wp_enqueue_scripts 只跑前台）
    wp_enqueue_script('jquery');
    ?>
    <div class="wrap kratos-mood-admin">
        <h1 class="wp-heading-inline"><?php _e('每日心情灯', 'kratos'); ?></h1>
        <p class="description">
            <?php _e('每天一格颜色 + 一句话，年终汇成一张情绪热力图。前台用 <code>[mood_log]</code> 短代码展示。', 'kratos'); ?>
        </p>

        <div class="kratos-heatmap is-mood" data-labels="<?php echo esc_attr(wp_json_encode($labels)); ?>" style="max-width:820px;margin-top:16px;">
            <?php echo kratos_mood_render_input_card(); ?>
        </div>

        <h2 style="margin-top:32px;"><?php printf(esc_html__('最近记录（共 %d 天）', 'kratos'), $total); ?></h2>
        <?php if (empty($recent)) { ?>
            <p><?php _e('还没有任何记录，从上方开始今天的心情。', 'kratos'); ?></p>
        <?php } else { ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:820px;">
            <thead>
                <tr>
                    <th style="width:110px;"><?php _e('日期', 'kratos'); ?></th>
                    <th style="width:110px;"><?php _e('心情', 'kratos'); ?></th>
                    <th><?php _e('一句话', 'kratos'); ?></th>
                    <th style="width:80px;"><?php _e('操作', 'kratos'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $r) {
                $m = (int) $r['mood'];
                $lab = isset($labels[$m]) ? $labels[$m] : array('emoji' => '', 'label' => '');
            ?>
                <tr>
                    <td><?php echo esc_html($r['date']); ?></td>
                    <td><span style="font-size:18px;"><?php echo esc_html($lab['emoji']); ?></span> <?php echo esc_html($lab['label']); ?></td>
                    <td><?php echo esc_html($r['note']); ?></td>
                    <td>
                        <form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e('确定删除该天记录？', 'kratos'); ?>');">
                            <?php wp_nonce_field('kratos_mood_admin'); ?>
                            <input type="hidden" name="date" value="<?php echo esc_attr($r['date']); ?>">
                            <button type="submit" name="kratos_mood_delete" value="1" class="button-link-delete" style="color:#b32d2e;background:none;border:none;cursor:pointer;padding:0;">
                                <?php _e('删除', 'kratos'); ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php } ?>

        <style>
        .kratos-mood-admin .kratos-heatmap.is-mood{
            --khs-bg-1:#f6f7f7; --khs-fg:#1d2327; --khs-fg-soft:#3c434a; --khs-fg-mute:#646970;
            --khs-accent:#2271b1;
            --khs-line:#dcdcde; --khs-line-strong:#c3c4c7;
            --khs-card-bg:#fff; --khs-card-shadow:0 1px 1px rgba(0,0,0,.04);
        }
        <?php echo kratos_mood_css(); ?>
        </style>
        <script>
        window.kratosMoodLog = {
            ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
            nonce:   <?php echo wp_json_encode(wp_create_nonce('kratos_mood_save')); ?>,
            canEdit: true
        };
        <?php echo kratos_mood_js(); ?>
        </script>
    </div>
    <?php
}

/**
 * AJAX：保存心情。
 */
function kratos_mood_ajax_save()
{
    if (!kratos_mood_can_edit()) wp_send_json_error(array('msg' => __('无权限', 'kratos')), 403);
    check_ajax_referer('kratos_mood_save', 'nonce');

    $mood = isset($_POST['mood']) ? (int) $_POST['mood'] : 0;
    $note = isset($_POST['note']) ? wp_unslash($_POST['note']) : '';
    $date = isset($_POST['date']) && $_POST['date'] ? sanitize_text_field(wp_unslash($_POST['date'])) : date('Y-m-d');

    if ($mood < 1 || $mood > 5) wp_send_json_error(array('msg' => __('心情等级不合法', 'kratos')), 400);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) wp_send_json_error(array('msg' => __('日期格式不合法', 'kratos')), 400);

    $note = sanitize_text_field($note);
    if (mb_strlen($note) > 140) $note = mb_substr($note, 0, 140);

    global $wpdb;
    $table = kratos_mood_table();
    $wpdb->replace($table, array(
        'date'       => $date,
        'mood'       => $mood,
        'note'       => $note,
        'created_at' => current_time('mysql'),
    ), array('%s', '%d', '%s', '%s'));

    wp_send_json_success(array('msg' => __('已保存', 'kratos')));
}
add_action('wp_ajax_kratos_mood_save', 'kratos_mood_ajax_save');

/**
 * AJAX：获取指定年份/范围数据。
 */
function kratos_mood_ajax_get()
{
    $year = isset($_GET['year']) && $_GET['year'] ? absint($_GET['year']) : null;
    $time_range = isset($_GET['time_range']) ? max(30, absint($_GET['time_range'])) : 365;
    $data = kratos_mood_get_data($year, $time_range);
    wp_send_json(array(
        'data'       => $data['raw_data'],
        'stats'      => $data['stats'],
        'year'       => $year,
        'time_range' => $time_range,
    ));
}
add_action('wp_ajax_kratos_mood_get', 'kratos_mood_ajax_get');
add_action('wp_ajax_nopriv_kratos_mood_get', 'kratos_mood_ajax_get');

/**
 * 内联 CSS：只写 mood 专属部分；.kratos-heatmap 外壳 / .kph-* 骨架
 * 复用 theme-post-heatmap.php 已经入队的样式（当同一页面既有 mood 又
 * 有 heatmap 时天然共享；仅有 mood 时下面这段也够用）。
 */
function kratos_mood_css()
{
    return <<<CSS
/* —— 变量兜底（页面若已有 post-heatmap，会被其自身样式先行覆盖） */
.kratos-heatmap.is-mood {
    --khs-bg-1:#f5f5f5; --khs-bg-2:#f0f0f0; --khs-bg-3:#ebebeb;
    --khs-fg:#333; --khs-fg-soft:#444; --khs-fg-dim:#777; --khs-fg-mute:#999;
    --khs-accent:#D68C4E;
    --khs-line: rgba(0,0,0,.08); --khs-line-strong: rgba(0,0,0,.16);
    --khs-card-bg:#fff;
    --khs-card-shadow: 0 1px 3px rgba(0,0,0,.06);
    margin-bottom: 20px;
    color: var(--khs-fg);
}
.kratos-heatmap.is-mood .kph-header{
    display:flex;align-items:center;flex-wrap:wrap;gap:14px;
    padding:20px 24px;margin-bottom:18px;
    background:var(--khs-card-bg);border:1px solid var(--khs-line);
    border-radius:14px;box-shadow:var(--khs-card-shadow);
}
.kratos-heatmap.is-mood .kph-title-icon{
    display:inline-flex;align-items:center;justify-content:center;
    width:34px;height:34px;border-radius:10px;
    background:linear-gradient(135deg,var(--khs-bg-2) 0%,var(--khs-bg-3) 100%);
    color:var(--khs-accent);font-size:18px;
}
.kratos-heatmap.is-mood .kph-title{margin:0;font-size:20px;font-weight:700;line-height:1.3;color:var(--khs-fg);flex:1}
.kratos-heatmap.is-mood .kph-body{
    padding:20px 24px;background:var(--khs-card-bg);
    border:1px solid var(--khs-line);border-radius:14px;box-shadow:var(--khs-card-shadow);
}
.kratos-heatmap.is-mood .kph-canvas{min-height:150px}
.kratos-heatmap.is-mood .kph-wrapper{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap}
.kratos-heatmap.is-mood .kph-graph{
    display:flex;align-items:flex-start;gap:6px;max-width:100%;
    overflow-x:auto;overflow-y:visible;-webkit-overflow-scrolling:touch;scrollbar-width:thin;
    padding:4px 6px 4px 0;
}
.kratos-heatmap.is-mood .kph-main{padding-right:4px}
.kratos-heatmap.is-mood .kph-weekdays{
    display:flex;flex-direction:column;gap:2px;margin-top:14px;flex-shrink:0;
    position:sticky;left:0;background:var(--khs-card-bg);padding-right:4px;z-index:1;
}
.kratos-heatmap.is-mood .kph-weekday{width:13px;height:13px;font-size:9px;line-height:13px;text-align:center;color:var(--khs-fg-mute)}
.kratos-heatmap.is-mood .kph-main{position:relative;flex-shrink:0}
.kratos-heatmap.is-mood .kph-months{position:relative;height:14px}
.kratos-heatmap.is-mood .kph-month{position:absolute;font-size:11px;font-weight:600;color:var(--khs-fg-soft);transform:translateX(-50%);white-space:nowrap}
.kratos-heatmap.is-mood .kph-grid{display:grid !important;grid-template-rows:repeat(7,13px) !important;gap:2px !important}
.kratos-heatmap.is-mood .kph-cell{
    width:13px;height:13px;border-radius:2px;background:var(--khs-line);
    cursor:pointer;transition:transform .15s ease,filter .15s ease;
}
.kratos-heatmap.is-mood .kph-cell:hover{transform:scale(1.35);filter:brightness(1.05);outline:1px solid var(--khs-accent)}
/* tooltip 基础样式（当页面只有 mood_log、没有 post_heatmap 时避免样式缺失） */
.kph-tooltip{
    position:absolute;padding:8px 12px;background:rgba(0,0,0,.88);color:#fff;
    font-size:12px;border-radius:6px;pointer-events:none;opacity:0;
    transition:opacity .15s ease;z-index:9999;line-height:1.5;
    box-shadow:0 6px 20px rgba(0,0,0,.2);
}
/* mood 5 档色阶（比 heatmap 多一档） */
.kratos-heatmap.is-mood .kph-cell.level-1{background:color-mix(in srgb,var(--khs-accent) 18%,transparent)}
.kratos-heatmap.is-mood .kph-cell.level-2{background:color-mix(in srgb,var(--khs-accent) 38%,transparent)}
.kratos-heatmap.is-mood .kph-cell.level-3{background:color-mix(in srgb,var(--khs-accent) 60%,transparent)}
.kratos-heatmap.is-mood .kph-cell.level-4{background:color-mix(in srgb,var(--khs-accent) 80%,transparent)}
.kratos-heatmap.is-mood .kph-cell.level-5{background:var(--khs-accent)}
.kph-tooltip{display:block}
.kph-tooltip .kml-tt-mood{display:inline-flex;align-items:center;gap:6px;line-height:1}
.kph-tooltip .kml-tt-emoji{font-size:16px;line-height:1;display:inline-flex;align-items:center}
.kph-tooltip .kml-tt-date{color:rgba(255,255,255,.7);font-size:11px;margin-bottom:2px}
.kph-tooltip .kml-tt-note{color:#fff;margin-top:4px;font-style:italic;max-width:220px;white-space:normal;line-height:1.5}

.kratos-heatmap.is-mood .kph-legend{
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    margin-top:14px;padding-top:12px;border-top:1px dashed var(--khs-line-strong);
    font-size:12px;color:var(--khs-fg-mute);
}
.kratos-heatmap.is-mood .kml-legend-moods{display:flex;gap:14px;flex-wrap:wrap}
.kratos-heatmap.is-mood .kml-legend-moods span{display:inline-flex;align-items:center;gap:4px;line-height:1}
.kratos-heatmap.is-mood .kml-legend-moods em{font-style:normal;font-size:15px;line-height:1;display:inline-flex;align-items:center}
.kratos-heatmap.is-mood .kph-legend-colors{display:flex;gap:2px}
.kratos-heatmap.is-mood .kph-legend-cell{width:12px;height:12px;border-radius:2px;background:var(--khs-line)}
.kratos-heatmap.is-mood .kph-legend-cell.level-1{background:color-mix(in srgb,var(--khs-accent) 18%,transparent)}
.kratos-heatmap.is-mood .kph-legend-cell.level-2{background:color-mix(in srgb,var(--khs-accent) 38%,transparent)}
.kratos-heatmap.is-mood .kph-legend-cell.level-3{background:color-mix(in srgb,var(--khs-accent) 60%,transparent)}
.kratos-heatmap.is-mood .kph-legend-cell.level-4{background:color-mix(in srgb,var(--khs-accent) 80%,transparent)}
.kratos-heatmap.is-mood .kph-legend-cell.level-5{background:var(--khs-accent)}

.kratos-heatmap.is-mood .kph-year-tags{
    flex:1 1 130px;display:grid;grid-template-columns:repeat(auto-fill,minmax(64px,1fr));gap:6px;align-content:start;
}
.kratos-heatmap.is-mood .kph-year-tag{
    font-size:13px;font-weight:600;color:var(--khs-fg-soft);
    padding:5px 12px;min-height:30px;
    border-radius:6px;background:transparent;border:1px solid var(--khs-line);
    cursor:pointer;line-height:1.4;white-space:nowrap;
    display:inline-flex;align-items:center;justify-content:center;
    font-family:inherit;
    transition:background .2s,border-color .2s,color .2s;
}
.kratos-heatmap.is-mood .kph-year-tag:hover{border-color:var(--khs-accent);color:var(--khs-accent)}
.kratos-heatmap.is-mood .kph-year-tag.is-active{background:var(--khs-accent);border-color:var(--khs-accent);color:#fff;cursor:default}

.kratos-heatmap.is-mood .kph-stats{
    display:flex;flex-wrap:wrap;gap:20px;margin-top:16px;padding-top:16px;
    border-top:1px dashed var(--khs-line-strong);font-size:13px;
}
.kratos-heatmap.is-mood .kph-stats-block{flex:1;min-width:200px}
.kratos-heatmap.is-mood .kph-stats-block-title{
    font-size:12px;font-weight:700;letter-spacing:.5px;color:var(--khs-accent);
    margin-bottom:8px;text-transform:uppercase;
}
.kratos-heatmap.is-mood .kph-stats-item{
    display:flex;justify-content:space-between;align-items:center;
    margin:5px 0;line-height:1.5;color:var(--khs-fg-soft);
}
.kratos-heatmap.is-mood .kph-stats-item .kph-stats-label{color:var(--khs-fg-mute)}
.kratos-heatmap.is-mood .kph-stats-item .kph-stats-value{color:var(--khs-fg);font-weight:600}
.kratos-heatmap.is-mood .kml-mood-bar{display:flex;align-items:center;gap:8px;margin:6px 0;line-height:1}
.kratos-heatmap.is-mood .kml-mood-bar em{font-style:normal;font-size:15px;width:22px;display:inline-flex;align-items:center;justify-content:center;line-height:1}
.kratos-heatmap.is-mood .kml-mood-bar .bar{flex:1;height:6px;background:var(--khs-line);border-radius:3px;overflow:hidden}
.kratos-heatmap.is-mood .kml-mood-bar .fill{height:100%;background:var(--khs-accent);border-radius:3px;transition:width .4s ease}
.kratos-heatmap.is-mood .kml-mood-bar .pct{font-size:12px;color:var(--khs-fg-mute);width:42px;text-align:right}

/* —— 今日录入卡 */
.kratos-heatmap.is-mood .kml-input-card{
    background:var(--khs-card-bg);border:1px solid var(--khs-line);border-radius:14px;
    padding:22px 24px;margin-bottom:18px;box-shadow:var(--khs-card-shadow);
}
.kratos-heatmap.is-mood .kml-input-head{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.kratos-heatmap.is-mood .kml-input-head h3{margin:0;font-size:16px;color:var(--khs-fg);font-weight:600}
.kratos-heatmap.is-mood .kml-input-head .today{margin-left:auto;font-size:12px;color:var(--khs-fg-mute)}
.kratos-heatmap.is-mood .kml-mood-picker{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.kratos-heatmap.is-mood .kml-mood-btn{
    flex:1;min-width:82px;padding:12px 8px;border:1px solid var(--khs-line);
    background:var(--khs-bg-1);border-radius:10px;cursor:pointer;
    display:flex;flex-direction:column;align-items:center;gap:4px;transition:.15s;
    color:inherit;font-family:inherit;
}
.kratos-heatmap.is-mood .kml-mood-btn em{font-style:normal;font-size:22px;line-height:1;display:inline-flex;align-items:center;justify-content:center}
.kratos-heatmap.is-mood .kml-mood-btn span{font-size:12px;color:var(--khs-fg-mute);line-height:1.4}
.kratos-heatmap.is-mood .kml-mood-btn:hover{border-color:var(--khs-accent);transform:translateY(-1px)}
.kratos-heatmap.is-mood .kml-mood-btn.is-active{border-color:var(--khs-accent)}
.kratos-heatmap.is-mood .kml-mood-btn.is-active span{color:var(--khs-fg);font-weight:600}
.kratos-heatmap.is-mood .kml-mood-btn[data-mood="1"].is-active{background:color-mix(in srgb,var(--khs-accent) 18%,transparent)}
.kratos-heatmap.is-mood .kml-mood-btn[data-mood="2"].is-active{background:color-mix(in srgb,var(--khs-accent) 38%,transparent)}
.kratos-heatmap.is-mood .kml-mood-btn[data-mood="3"].is-active{background:color-mix(in srgb,var(--khs-accent) 60%,transparent)}
.kratos-heatmap.is-mood .kml-mood-btn[data-mood="4"].is-active{background:color-mix(in srgb,var(--khs-accent) 80%,transparent);color:#fff}
.kratos-heatmap.is-mood .kml-mood-btn[data-mood="4"].is-active span{color:#fff}
.kratos-heatmap.is-mood .kml-mood-btn[data-mood="5"].is-active{background:var(--khs-accent);color:#fff}
.kratos-heatmap.is-mood .kml-mood-btn[data-mood="5"].is-active span{color:#fff}
.kratos-heatmap.is-mood .kml-note-wrap{position:relative}
.kratos-heatmap.is-mood .kml-note{
    width:100%;min-height:56px;padding:12px 60px 12px 14px;font-family:inherit;font-size:14px;
    background:var(--khs-bg-1);border:1px solid var(--khs-line);border-radius:10px;resize:vertical;
    color:var(--khs-fg);box-sizing:border-box;
}
.kratos-heatmap.is-mood .kml-note:focus{outline:none;border-color:var(--khs-accent);background:var(--khs-card-bg)}
.kratos-heatmap.is-mood .kml-counter{position:absolute;right:12px;bottom:10px;font-size:11px;color:var(--khs-fg-mute)}
.kratos-heatmap.is-mood .kml-actions{display:flex;justify-content:flex-end;align-items:center;margin-top:12px;gap:12px}
.kratos-heatmap.is-mood .kml-msg{font-size:12px;color:var(--khs-fg-mute);min-height:16px}
.kratos-heatmap.is-mood .kml-msg.is-ok{color:var(--khs-accent)}
.kratos-heatmap.is-mood .kml-btn-save{
    padding:8px 22px;background:var(--khs-accent);color:#fff;border:none;border-radius:8px;
    font-size:13px;cursor:pointer;font-weight:500;font-family:inherit;
}
.kratos-heatmap.is-mood .kml-btn-save:hover{filter:brightness(1.06)}
.kratos-heatmap.is-mood .kml-btn-save:disabled{opacity:.6;cursor:wait}

@media (max-width:720px){
    .kratos-heatmap.is-mood .kph-header{padding:14px 16px}
    .kratos-heatmap.is-mood .kph-title{font-size:16px}
    .kratos-heatmap.is-mood .kph-body{padding:14px 16px}
    .kratos-heatmap.is-mood .kml-input-card{padding:16px}
    .kratos-heatmap.is-mood .kml-mood-btn{min-width:calc(20% - 8px);padding:10px 4px}
    .kratos-heatmap.is-mood .kml-mood-btn em{font-size:20px}
    .kratos-heatmap.is-mood .kph-year-tags{flex:0 1 auto;display:flex;flex-wrap:wrap}
    .kratos-heatmap.is-mood .kph-year-tag{font-size:12px;padding:4px 10px}
    .kratos-heatmap.is-mood .kph-stats-block{min-width:100%}
}

html[data-theme="dark"] .kratos-heatmap.is-mood,
body.dark .kratos-heatmap.is-mood{
    --khs-bg-1:#2a2e35; --khs-bg-2:#2a2e35; --khs-bg-3:#333842;
    --khs-fg:#d6d8db; --khs-fg-soft:#b8bbc0; --khs-fg-dim:#8b919a; --khs-fg-mute:#6f747e;
    --khs-accent:#e0a370;
    --khs-line:rgba(255,255,255,.10); --khs-line-strong:rgba(255,255,255,.18);
    --khs-card-bg:#1c1f24;
    --khs-card-shadow:0 1px 2px rgba(0,0,0,.5);
}
CSS;
}

/**
 * 内联 JS。
 */
function kratos_mood_js()
{
    $i18n = wp_json_encode(array(
        'weekdays'   => array(__('日', 'kratos'), __('一', 'kratos'), __('二', 'kratos'), __('三', 'kratos'), __('四', 'kratos'), __('五', 'kratos'), __('六', 'kratos')),
        'months'     => array(__('1月', 'kratos'), __('2月', 'kratos'), __('3月', 'kratos'), __('4月', 'kratos'), __('5月', 'kratos'), __('6月', 'kratos'), __('7月', 'kratos'), __('8月', 'kratos'), __('9月', 'kratos'), __('10月', 'kratos'), __('11月', 'kratos'), __('12月', 'kratos')),
        'recentYear' => __('最近一年', 'kratos'),
        'yearLabel'  => __('%d 年', 'kratos'),
        'legendLess' => __('低', 'kratos'),
        'legendMore' => __('高', 'kratos'),
        'loading'    => __('加载中...', 'kratos'),
        'loadFail'   => __('数据加载失败', 'kratos'),
        'saveOk'     => __('已保存 · 今日心情已记录', 'kratos'),
        'saveFail'   => __('保存失败', 'kratos'),
        'notRecord'  => __('未记录', 'kratos'),
        'overview'   => __('年度概览', 'kratos'),
        'distTitle'  => __('心情分布', 'kratos'),
        'days'       => __('天', 'kratos'),
        'streak'     => __('连续打卡', 'kratos'),
        'maxStreak'  => __('最长连续', 'kratos'),
        'recorded'   => __('记录天数', 'kratos'),
        'avg'        => __('平均心情', 'kratos'),
    ));
    return <<<JS
(function(\$){
    var I18N = $i18n;
    function pad(n){return String(n).padStart(2,'0');}
    function fmt(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());}
    function esc(s){return \$('<div>').text(s==null?'':s).html();}
    function weekMatrix(start,end){
        var m=[],first=new Date(start);
        first.setDate(start.getDate()-start.getDay());
        var cur=new Date(first),wk=new Array(7).fill(null);
        while(cur<=end){
            wk[cur.getDay()]=new Date(cur);
            if(cur.getDay()===6){m.push(wk);wk=new Array(7).fill(null);}
            cur.setDate(cur.getDate()+1);
        }
        if(wk.some(function(x){return x!==null;}))m.push(wk);
        return m;
    }
    function monthLabels(wm,cs,cg){
        var out=[],last=-1;
        wm.forEach(function(w,i){
            var f=w.find(function(d){return d!==null;});
            if(!f)return;
            var mo=f.getMonth();
            if(mo===last)return;
            out.push({text:I18N.months[mo],offset:i*(cs+cg)+cs/2});
            last=mo;
        });
        return out;
    }
    function render(\$canvas,data,stats,year,timeRange,labels){
        var start,end;
        if(year){start=new Date(year+'-01-01');end=new Date(year+'-12-31');}
        else{end=new Date();start=new Date();start.setDate(end.getDate()-timeRange);}
        var wm=weekMatrix(start,end),weeks=wm.length,cs=13,cg=2;
        \$canvas.empty();
        var \$tt=\$('.kph-tooltip');
        if(!\$tt.length)\$tt=\$('<div class="kph-tooltip"></div>').appendTo('body');
        var \$wrap=\$('<div class="kph-wrapper"></div>');
        var \$graph=\$('<div class="kph-graph"></div>');
        var \$wd=\$('<div class="kph-weekdays"></div>');
        I18N.weekdays.forEach(function(d,i){\$wd.append('<div class="kph-weekday">'+(i%2===1?d:'')+'</div>');});
        \$graph.append(\$wd);
        var \$main=\$('<div class="kph-main"></div>');
        var \$mo=\$('<div class="kph-months"></div>').css('width',(weeks*(cs+cg))+'px');
        monthLabels(wm,cs,cg).forEach(function(l){
            \$mo.append('<div class="kph-month" style="left:'+l.offset+'px;">'+l.text+'</div>');
        });
        \$main.append(\$mo);
        var \$grid=\$('<div class="kph-grid"></div>').css({'grid-template-columns':'repeat('+weeks+', '+cs+'px)'});
        var today=new Date();
        wm.forEach(function(week,wi){
            week.forEach(function(d,di){
                var ds='',rec=null,lv=0,fu=false;
                if(d){ds=fmt(d);fu=d>today;if(!fu)rec=data[ds]||null;if(rec)lv=rec.mood;}
                var \$c=\$('<div class="kph-cell'+(lv?' level-'+lv:'')+'"></div>');
                \$c.css({'grid-row':(di+1),'grid-column':(wi+1)});
                if(ds && !fu){
                    \$c.on('mouseenter',function(e){
                        var html='<div class="kml-tt-date">'+ds+'</div>';
                        if(rec){
                            var lab=labels[rec.mood]||{emoji:'',label:''};
                            html+='<div class="kml-tt-mood"><span class="kml-tt-emoji">'+lab.emoji+'</span><span>'+esc(lab.label)+'</span></div>';
                            if(rec.note)html+='<div class="kml-tt-note">"'+esc(rec.note)+'"</div>';
                        } else {
                            html+='<span style="color:rgba(255,255,255,.5)">'+I18N.notRecord+'</span>';
                        }
                        \$tt.html(html).css({top:e.pageY+12,left:e.pageX+12,opacity:1});
                    }).on('mousemove',function(e){\$tt.css({top:e.pageY+12,left:e.pageX+12});})
                      .on('mouseleave',function(){\$tt.css('opacity',0);});
                }
                \$grid.append(\$c);
            });
        });
        \$main.append(\$grid);
        \$graph.append(\$main);
        \$wrap.append(\$graph);
        var years=\$canvas.closest('.kratos-heatmap').data('years')||[];
        if(typeof years==='string'){try{years=JSON.parse(years);}catch(e){years=[];}}
        var \$tags=\$('<div class="kph-year-tags"></div>');
        \$tags.append('<button type="button" class="kph-year-tag'+(!year?' is-active':'')+'" data-year="">'+I18N.recentYear+'</button>');
        years.forEach(function(y){
            \$tags.append('<button type="button" class="kph-year-tag'+(String(year)===String(y)?' is-active':'')+'" data-year="'+y+'">'+I18N.yearLabel.replace('%d',y)+'</button>');
        });
        \$wrap.append(\$tags);
        \$canvas.append(\$wrap);
        // legend
        var \$lg=\$('<div class="kph-legend"></div>');
        var \$mo2=\$('<div class="kml-legend-moods"></div>');
        for(var i=1;i<=5;i++){
            var lab=labels[i]||{emoji:'',label:''};
            \$mo2.append('<span><em>'+lab.emoji+'</em>'+esc(lab.label)+'</span>');
        }
        \$lg.append(\$mo2);
        var \$rc=\$('<div style="display:flex;align-items:center;gap:8px"></div>');
        \$rc.append('<span>'+I18N.legendLess+'</span>');
        var \$lc=\$('<div class="kph-legend-colors"></div>');
        for(var l=1;l<=5;l++)\$lc.append('<div class="kph-legend-cell level-'+l+'"></div>');
        \$rc.append(\$lc).append('<span>'+I18N.legendMore+'</span>');
        \$lg.append(\$rc);
        \$canvas.append(\$lg);
        // stats
        var \$st=\$('<div class="kph-stats"></div>');
        var avgLv=Math.round(stats.avg_mood||0);
        var avgLab=(labels[avgLv]||{emoji:'-'}).emoji+' '+(stats.avg_mood||0)+' / 5';
        var base='<div class="kph-stats-block"><div class="kph-stats-block-title">'+I18N.overview+'</div>'+
            item(I18N.recorded,(stats.total||0)+' '+I18N.days)+
            item(I18N.streak,(stats.streak||0)+' '+I18N.days)+
            item(I18N.maxStreak,(stats.max_streak||0)+' '+I18N.days)+
            item(I18N.avg,stats.total?avgLab:'-')+
            '</div>';
        \$st.append(base);
        var dist='<div class="kph-stats-block"><div class="kph-stats-block-title">'+I18N.distTitle+'</div>';
        for(var lv=5;lv>=1;lv--){
            var d=(stats.distribution&&stats.distribution[lv])||{percent:0};
            var lab2=labels[lv]||{emoji:''};
            dist+='<div class="kml-mood-bar"><em>'+lab2.emoji+'</em>'+
                  '<div class="bar"><div class="fill" style="width:'+d.percent+'%"></div></div>'+
                  '<span class="pct">'+d.percent+'%</span></div>';
        }
        dist+='</div>';
        \$st.append(dist);
        \$canvas.append(\$st);
    }
    function item(l,v){return '<div class="kph-stats-item"><span class="kph-stats-label">'+l+'</span><span class="kph-stats-value">'+v+'</span></div>';}

    \$(function(){
        \$('.kratos-heatmap.is-mood .kml-canvas').each(function(){
            var \$c=\$(this),id=this.id;
            var cfg=JSON.parse(\$('.kml-data-'+id).html()||'{}');
            var labels=\$c.closest('.kratos-heatmap').data('labels')||{};
            if(typeof labels==='string'){try{labels=JSON.parse(labels);}catch(e){labels={};}}
            if(!cfg.data)return;
            render(\$c,cfg.data,cfg.stats||{},cfg.year,cfg.time_range,labels);
        });
        \$(document).on('click','.kratos-heatmap.is-mood .kph-year-tag',function(){
            var \$t=\$(this);
            if(\$t.hasClass('is-active'))return;
            var \$box=\$t.closest('.kratos-heatmap'),
                \$c=\$box.find('.kml-canvas'),
                y=\$t.data('year')||null,
                tr=\$box.data('time-range'),
                labels=\$box.data('labels')||{};
            \$c.html('<div style="padding:20px;color:var(--khs-fg-mute);">'+I18N.loading+'</div>');
            \$.ajax({url:kratosMoodLog.ajaxUrl,type:'GET',dataType:'json',
                data:{action:'kratos_mood_get',year:y,time_range:tr},
                success:function(r){render(\$c,r.data||{},r.stats||{},r.year,r.time_range,labels);},
                error:function(){\$c.html('<div style="padding:20px;color:var(--khs-fg-mute);">'+I18N.loadFail+'</div>');}
            });
        });

        // 录入卡：切换心情
        \$(document).on('click','.kratos-heatmap.is-mood .kml-mood-btn',function(){
            var \$b=\$(this),\$card=\$b.closest('.kml-input-card');
            \$card.find('.kml-mood-btn').removeClass('is-active');
            \$b.addClass('is-active');
        });
        // 字数
        \$(document).on('input','.kratos-heatmap.is-mood .kml-note',function(){
            var v=\$(this).val();
            \$(this).next('.kml-counter').text(v.length+' / 140');
        });
        // 保存
        \$(document).on('click','.kratos-heatmap.is-mood .kml-btn-save',function(){
            var \$btn=\$(this),\$card=\$btn.closest('.kml-input-card');
            var \$sel=\$card.find('.kml-mood-btn.is-active');
            var \$msg=\$card.find('.kml-msg');
            if(!\$sel.length){\$msg.removeClass('is-ok').text('请选择心情');return;}
            var mood=parseInt(\$sel.data('mood'),10);
            var note=\$card.find('.kml-note').val();
            \$btn.prop('disabled',true);
            \$msg.removeClass('is-ok').text('');
            \$.ajax({url:kratosMoodLog.ajaxUrl,type:'POST',dataType:'json',
                data:{action:'kratos_mood_save',nonce:kratosMoodLog.nonce,mood:mood,note:note},
                success:function(r){
                    \$btn.prop('disabled',false);
                    if(r&&r.success){\$msg.addClass('is-ok').text(I18N.saveOk);}
                    else {\$msg.removeClass('is-ok').text((r&&r.data&&r.data.msg)||I18N.saveFail);}
                },
                error:function(){\$btn.prop('disabled',false);\$msg.removeClass('is-ok').text(I18N.saveFail);}
            });
        });
    });
})(jQuery);
JS;
}
