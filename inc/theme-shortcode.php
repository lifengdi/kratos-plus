<?php

/**
 * 文章短代码
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2022.01.26
 */

function h2title($atts, $content = null, $code = "")
{
    $return = '<h2 class="title">';
    $return .= $content;
    $return .= '</h2>';
    return $return;
}
add_shortcode('h2title', 'h2title');

function success($atts, $content = null, $code = "")
{
    $return = '<div class="alert alert-success">';
    $return .= $content;
    $return .= '</div>';
    return $return;
}
add_shortcode('success', 'success');

function info($atts, $content = null, $code = "")
{
    $return = '<div class="alert alert-info">';
    $return .= $content;
    $return .= '</div>';
    return $return;
}
add_shortcode('info', 'info');

function warning($atts, $content = null, $code = "")
{
    $return = '<div class="alert alert-warning">';
    $return .= $content;
    $return .= '</div>';
    return $return;
}
add_shortcode('warning', 'warning');

function danger($atts, $content = null, $code = "")
{
    $return = '<div class="alert alert-danger">';
    $return .= $content;
    $return .= '</div>';
    return $return;
}
add_shortcode('danger', 'danger');

function wymusic($atts, $content = null, $code = "")
{
    $return = '<div class="mb-3"><iframe style="width:100%" frameborder="no" border="0" marginwidth="0" marginheight="0" height=86 src="//music.163.com/outchain/player?type=2&id=';
    $return .= $content;
    $return .= '&auto=' . kratos_option('g_163mic', false) . '&height=66"></iframe></div>';
    return $return;
}
add_shortcode('music', 'wymusic');

function bdbtn($atts, $content = null, $code = "")
{
    $return = '<a class="downbtn" href="';
    $return .= $content;
    $return .= '" target="_blank"><i class="kicon i-download me-1"></i>立即下载</a>';
    return $return;
}
add_shortcode('bdbtn', 'bdbtn');

function kbd($atts, $content = null, $code = "")
{
    $return = '<kbd>';
    $return .= $content;
    $return .= '</kbd>';
    return $return;
}
add_shortcode('kbd', 'kbd');

function nrmark($atts, $content = null, $code = "")
{
    $return = '<mark>';
    $return .= $content;
    $return .= '</mark>';
    return $return;
}
add_shortcode('mark', 'nrmark');

function striped($atts, $content = null, $code = "")
{
    $return = '<div class="progress"><div class="progress-bar" role="progressbar" style="width:';
    $return .= $content;
    $return .= '%;" aria-valuenow="';
    $return .= $content;
    $return .= '" aria-valuemin="0" aria-valuemax="100">';
    $return .= $content;
    $return .= '%</div></div>';
    return $return;
}
add_shortcode('striped', 'striped');

function successbox($atts, $content = null, $code = "")
{
    extract(shortcode_atts(array("title" => __('标题内容', 'kratos')), $atts));
    $return = '<div class="card border-success text-white mb-3"><div class="card-header bg-success">';
    $return .= $title;
    $return .= '</div><div class="card-body"><p class="card-text">';
    $return .= $content;
    $return .= '</p></div></div>';
    return $return;
}
add_shortcode('successbox', 'successbox');

function infobox($atts, $content = null, $code = "")
{
    extract(shortcode_atts(array("title" => __('标题内容', 'kratos')), $atts));
    $return = '<div class="card border-info text-white mb-3"><div class="card-header bg-info">';
    $return .= $title;
    $return .= '</div><div class="card-body"><p class="card-text">';
    $return .= $content;
    $return .= '</p></div></div>';
    return $return;
}
add_shortcode('infobox', 'infobox');

function warningbox($atts, $content = null, $code = "")
{
    extract(shortcode_atts(array("title" => __('标题内容', 'kratos')), $atts));
    $return = '<div class="card border-warning text-white mb-3"><div class="card-header bg-warning">';
    $return .= $title;
    $return .= '</div><div class="card-body"><p class="card-text">';
    $return .= $content;
    $return .= '</p></div></div>';
    return $return;
}
add_shortcode('warningbox', 'warningbox');

function dangerbox($atts, $content = null, $code = "")
{
    extract(shortcode_atts(array("title" => __('标题内容', 'kratos')), $atts));
    $return = '<div class="card border-danger text-white mb-3"><div class="card-header bg-danger">';
    $return .= $title;
    $return .= '</div><div class="card-body"><p class="card-text">';
    $return .= $content;
    $return .= '</p></div></div>';
    return $return;
}
add_shortcode('dangerbox', 'dangerbox');

function vqq($atts, $content = null, $code = "")
{
    $return = '<div class="video-container"><iframe frameborder="0" src="https://v.qq.com/txp/iframe/player.html?vid=';
    $return .= $content;
    $return .= '" allowFullScreen="true"></iframe></div>';
    return $return;
}
add_shortcode('vqq', 'vqq');

function youtube($atts, $content = null, $code = "")
{
    $return = '<div class="video-container"><iframe height="498" width="750" src="https://www.youtube.com/embed/';
    $return .= $content;
    $return .= '" frameborder="0" allowfullscreen="allowfullscreen"></iframe></div>';
    return $return;
}
add_shortcode('youtube', 'youtube');

function bilibili($atts, $content = null, $code = "")
{
    $return = '<div class="video-container"><iframe src="//player.bilibili.com/player.html?bvid=';
    $return .= $content;
    $return .= '&page=1" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true"> </iframe></div>';
    return $return;
}
add_shortcode('bilibili', 'bilibili');

function reply($atts, $content = null)
{
    extract(shortcode_atts(array("notice" => '<div class="alert alert-primary text-center" role="alert">' . __('温馨提示：此处内容已隐藏，<a href="#comments">回复</a>后刷新页面即可查看！', 'kratos') . '</div>'), $atts));
    $userEmail = null;
    $user_ID = (int) wp_get_current_user()->ID;
    if ($user_ID > 0) {
        $userEmail = get_userdata($user_ID)->user_email;
        $adminUsers = get_users('role=Administrator');
        $adminEmails = array();
        foreach ($adminUsers as $user) {
            $adminEmails[] = $user->user_email;
        }
        $authorEmail = get_the_author_meta('user_email');
        array_push($adminEmails, $authorEmail);
        if (in_array($userEmail, $adminEmails)) {
            return $content;
        }
    } else {
        if (isset($_COOKIE['comment_author_email_' . COOKIEHASH])) {
            $userEmail = str_replace('%40', '@', $_COOKIE['comment_author_email_' . COOKIEHASH]);
        } else {
            return $notice;
        }
    }
    if (empty($userEmail)) {
        return $notice;
    }
    global $wpdb;
    $post_id = get_the_ID();
    if ($wpdb->get_results($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_approved = '1' AND comment_author_email = %s LIMIT 1", $post_id, $userEmail))) {
        return do_shortcode($content);
    } else {
        return $notice;
    }
}
add_shortcode('reply', 'reply');

function accordion($atts, $content = null, $code = "")
{
    extract(shortcode_atts(array("title" => __('标题内容', 'kratos')), $atts));
    $return = '<div class="accordion"><div class="acheader"><div class="icon"><i class="kicon i-plus"></i></div><span>';
    $return .= $title;
    $return .= '</span></div><div class="contents"><div class="inner">';
    $return .= do_shortcode($content);
    $return .= '</div></div></div>';
    return $return;
}
add_shortcode('accordion', 'accordion');

function dplayer($atts = array(), $content = '')
{
    static $instance = 0;
    $instance++;

    $atts = shortcode_atts(
        array(
            'autoplay'       => 'false',
            'theme'          => '#b7daff',
            'loop'           => 'false',
            'preload'        => 'auto',
            'src'            => '',
            'poster'         => '',
            'type'           => 'auto',
            'mutex'          => 'true',
            'iconsColor'     => '#ffffff'
        ),
        $atts,
        'dplayer'
    );

    $atts['autoplay']        = wp_validate_boolean($atts['autoplay']);
    $atts['theme']           = esc_attr($atts['theme']);
    $atts['loop']            = wp_validate_boolean($atts['loop']);
    $atts['preload']         = esc_attr($atts['preload']);
    $atts['src']             = esc_url_raw($atts['src']);
    $atts['poster']          = esc_url_raw($atts['poster']);
    $atts['type']            = strtolower(esc_attr($atts['type']));
    $atts['mutex']           = wp_validate_boolean($atts['mutex']);
    $atts['iconsColor']      = esc_attr($atts['iconsColor']);

    if (empty($atts['src'])) return;

    // 「性能优化 → 播放器脚本按需加载」会把全站无条件入队的 DPlayer 摘掉，
    // 由用到它的短码在此按需入队。短码运行于 the_content 阶段，页脚脚本尚未
    // 打印，此时 enqueue 仍然有效。
    wp_enqueue_script('dplayer');

    $output = sprintf(
        '<script> const dp%u = new DPlayer({ container: document.getElementById("dplayer-%u"), autoplay: %b, theme: "%s", loop: %b, preload: "%s", video: { url: "%s", type: "%s", pic: "%s", }, mutex: %b, iconsColor: "%s" }); </script>',
        $instance,
        $instance,
        $atts['autoplay'],
        $atts['theme'],
        $atts['loop'],
        $atts['preload'],
        $atts['src'],
        $atts['type'],
        $atts['poster'],
        $atts['mutex'],
        $atts['iconsColor']
    );

    $html = sprintf(
        '<p><div id="dplayer-%u"></div></p>',
        $instance
    );

    add_action('wp_footer', function () use ($output) {
        echo '		' . $output . "\n";
    }, 99999);

    return $html;
}
add_shortcode('dplayer', 'dplayer');

function override_wp_video_shortcode($html = '', $atts = array())
{
    if (empty($atts['src'])) {
        if (!empty($atts['mp4'])) {
            $atts['src'] = $atts['mp4'];
        } elseif (!empty($atts['m4v'])) {
            $atts['src'] = $atts['m4v'];
        } elseif (!empty($atts['webm'])) {
            $atts['src'] = $atts['webm'];
        } elseif (!empty($atts['ogv'])) {
            $atts['src'] = $atts['ogv'];
        } elseif (!empty($atts['wmv'])) {
            $atts['src'] = $atts['wmv'];
        } elseif (!empty($atts['flv'])) {
            $atts['src'] = $atts['flv'];
        }
    };

    $video_attr_strings = array();
    foreach ($atts as $k => $v) {
        if ($v == '') continue;
        $video_attr_strings[] = $k . '="' . esc_attr($v) . '"';
    }

    $html .= sprintf('[dplayer %s]', join(' ', $video_attr_strings));

    return do_shortcode($html);
}

if (!is_admin()) {
    add_filter('wp_video_shortcode_override', 'override_wp_video_shortcode', 1, 2);
}

add_action('init', 'more_button');
function more_button()
{
    if (!current_user_can('edit_posts') && !current_user_can('edit_pages')) {
        return;
    }
    if (get_user_option('rich_editing') == 'true') {
        add_filter('mce_external_plugins', 'add_plugin');
        add_filter('mce_buttons', 'register_button');
    }
}

function add_more_buttons($buttons)
{
    $buttons[] = 'hr';
    $buttons[] = 'wp_page';
    $buttons[] = 'fontsizeselect';
    $buttons[] = 'styleselect';
    return $buttons;
}
add_filter("mce_buttons", "add_more_buttons");

function register_button($buttons)
{
    array_push($buttons, " ", "h2title");
    array_push($buttons, " ", "kbd");
    array_push($buttons, " ", "mark");
    array_push($buttons, " ", "striped");
    array_push($buttons, " ", "bdbtn");
    array_push($buttons, " ", "reply");
    array_push($buttons, " ", "accordion");
    array_push($buttons, " ", "dplayer");
    array_push($buttons, " ", "music");
    array_push($buttons, " ", "vqq");
    array_push($buttons, " ", "youtube");
    array_push($buttons, " ", "bilibili");
    array_push($buttons, " ", "success");
    array_push($buttons, " ", "info");
    array_push($buttons, " ", "warning");
    array_push($buttons, " ", "danger");
    array_push($buttons, " ", "successbox");
    array_push($buttons, " ", "infoboxs");
    array_push($buttons, " ", "warningbox");
    array_push($buttons, " ", "dangerbox");
    return $buttons;
}

function add_plugin($plugin_array)
{
    $plugin_array['h2title'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['kbd'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['mark'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['striped'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['bdbtn'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['reply'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['accordion'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['music'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['vqq'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['youtube'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['bilibili'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['success'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['info'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['warning'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['danger'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['successbox'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['infoboxs'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['warningbox'] = ASSET_PATH . '/assets/js/buttons/more.js';
    $plugin_array['dangerbox'] = ASSET_PATH . '/assets/js/buttons/more.js';
    return $plugin_array;
}

// 定义短码处理函数
function countdown_shortcode( $atts ) {
    $default_atts = array(
        'type' => 'all', // 默认全部展示
        'title' => '' // 默认没有标题，虽然保留但不使用
    );
    $atts = shortcode_atts( $default_atts, $atts );

    $types = explode(',', str_replace(' ', '', $atts['type']));
    if (in_array('all', $types)) {
        $types = ['day', 'week','month', 'year'];
    }

    $valid_types = ['day', 'week','month', 'year'];
    $color_map = [
        'day' => '#B5EAD7',  // 明亮的薄荷绿
        'week' => '#FFDAC1', // 柔和的蜜桃粉
      'month' => '#E2F0CB', // 清新的淡黄绿
        'year' => '#FFB7B2'  // 温暖的珊瑚粉
    ];

    $style = '<style>
       .countdown-container {
            background-color: #FAF9F6;
            border-radius: 12px;
            padding: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
        }
       .countdown-item {
            height: 50px;
            position: relative;
            max-height: 80px;
            overflow: hidden;
        }
       .countdown-text {
            color: #868e96;
            font-size: 0.95em;
        }
       .countdown-progress-bar {
            height: 16px;
            background-color: #E8E8E8;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            margin-top: 3px;
        }
       .progress-bar-fill {
            height: 100%;
            border-radius: 8px;
            transition: width 1s ease;
            position: relative;
            background-image: repeating-linear-gradient(
                -45deg,
                rgba(255, 255, 255, 0.2),
                rgba(255, 255, 255, 0.2) 10px,
                transparent 10px,
                transparent 20px
            );
            background-size: 28px 28px;
            animation: spiral-move 1s linear infinite;
        }
       .countdown-percentage {
            font-size: 0.8em;
            color: #fff;
            position: absolute;
            top: 50%;
            right: 5px;
            transform: translateY(-50%);
            z-index: 2;
        }

        @keyframes spiral-move {
            0% {
                background-position: 0 0;
            }
            100% {
                background-position: 28px 0;
            }
        }
    </style>';

    $script = '<script>
        function updateCountdown() {
            const now = new Date();
            const types = '. json_encode($types). ';
            const colorMap = '. json_encode($color_map). ';
            const validTypes = '. json_encode($valid_types). ';

            types.forEach(type => {
                if (!validTypes.includes(type)) return;

                let elapsed = 0;
                let total = 0;
                let text = "";

                switch (type) {
                    case "day":
                        elapsed = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
                        total = 24 * 3600;
                        text = `今天已过去 ${Math.floor(elapsed / 3600)} 小时 ${Math.floor((elapsed % 3600) / 60)} 分钟`;
                        break;
                    case "week":
                        elapsed = (now.getDay() * 24 * 3600) + (now.getHours() * 3600) + (now.getMinutes() * 60) + now.getSeconds();
                        total = 7 * 24 * 3600;
                        text = `本周已过去 ${Math.floor(elapsed / (24 * 3600))} 天 ${Math.floor((elapsed % (24 * 3600)) / 3600)} 小时 ${Math.floor((elapsed % 3600) / 60)} 分钟`;
                        break;
                    case "month":
                        const year = now.getFullYear();
                        const month = now.getMonth() + 1;
                        const daysInMonth = new Date(year, month, 0).getDate();
                        elapsed = ((now.getDate() - 1) * 24 * 3600) + (now.getHours() * 3600) + (now.getMinutes() * 60) + now.getSeconds();
                        total = daysInMonth * 24 * 3600;
                        text = `本月已过去 ${Math.floor(elapsed / (24 * 3600))} 天 ${Math.floor((elapsed % (24 * 3600)) / 3600)} 小时 ${Math.floor((elapsed % 3600) / 60)} 分钟`;
                        break;
                    case "year":
                        const startOfYear = new Date(now.getFullYear(), 0, 1);
                        const diff = now - startOfYear;
                        elapsed = Math.floor(diff / 1000);
                        const isLeapYear = (new Date(now.getFullYear(), 1, 29).getDate() === 29);
                        total = (isLeapYear? 366 : 365) * 24 * 3600;
                        text = `今年已过去 ${Math.floor(elapsed / (24 * 3600))} 天 ${Math.floor((elapsed % (24 * 3600)) / 3600)} 小时 ${Math.floor((elapsed % 3600) / 60)} 分钟`;
                        break;
                }

                const percentage = Math.round((elapsed / total) * 100);
                const item = document.getElementById(`countdown-${type}`);
                if (item) {
                    const textElement = item.querySelector(".countdown-text");
                    const percentageElement = item.querySelector(".countdown-percentage");
                    const progressBarElement = item.querySelector(".progress-bar-fill");

                    textElement.textContent = text;
                    percentageElement.textContent = `${percentage}%`;
                    progressBarElement.style.width = `${percentage}%`;
                    progressBarElement.style.backgroundColor = colorMap[type];
                }
            });
        }

        document.addEventListener("DOMContentLoaded", () => {
            updateCountdown();
            setInterval(updateCountdown, 1000 * 60);
        });
    </script>';

    ob_start();
    echo $style;
    echo '<div class="countdown-container">';
    foreach ($types as $type) {
        if (!in_array($type, $valid_types)) continue;
       ?>
        <div class="countdown-item" id="countdown-<?php echo $type;?>">
            <span class="countdown-text"></span>
            <div class="countdown-progress-bar">
                <div class="progress-bar-fill">
                    <span class="countdown-percentage"></span>
                </div>
            </div>
        </div>
        <?php
    }
    echo '</div>';
    echo $script;
    return ob_get_clean();
}

add_shortcode('countdown', 'countdown_shortcode');