<?php

/**
 * 侧栏小工具
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos-plus fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2023.01.14
 */

// 添加小工具
function widgets_init()
{
    register_sidebar(array(
        'name' => __('主页侧边栏', 'kratos'),
        'id' => 'home_sidebar',
        'before_widget' => '<div class="widget %2$s">',
        'after_widget' => '</div>',
        'before_title' => '<div class="title">',
        'after_title' => '</div>',
    ));
    register_sidebar(array(
        'name' => __('文章侧边栏', 'kratos'),
        'id' => 'single_sidebar',
        'before_widget' => '<div class="widget %2$s">',
        'after_widget' => '</div>',
        'before_title' => '<div class="title">',
        'after_title' => '</div>',
    ));
    register_sidebar(array(
        'name' => __('页面侧边栏', 'kratos'),
        'id' => 'page_sidebar',
        'before_widget' => '<div class="widget %2$s">',
        'after_widget' => '</div>',
        'before_title' => '<div class="title">',
        'after_title' => '</div>',
    ));
}
add_action('widgets_init', 'widgets_init');

// 关闭默认小工具
function widget_unregister()
{
    // fix #502 #521
    // unregister_widget('WP_Widget_Block');
    unregister_widget('WP_Widget_Pages');
    unregister_widget('WP_Widget_Meta');
    unregister_widget('WP_Widget_Media_Image');
    unregister_widget('WP_Widget_Calendar');
    unregister_widget('WP_Widget_Recent_Posts');
    unregister_widget('WP_Widget_Recent_Comments');
    unregister_widget('WP_Widget_RSS');
    unregister_widget('WP_Widget_Search');
    unregister_widget('WP_Widget_Tag_Cloud');
    unregister_widget('WP_Nav_Menu_Widget');
    // 用自定义 widget_links 接管，复用友链页面的 Logo / 首字母占位展示逻辑
    unregister_widget('WP_Widget_Links');
}
add_action('widgets_init', 'widget_unregister');

// 分类目录计数
function cat_count_span($links)
{
    $links = str_replace('</a> (', '<span> / ', $links);
    $links = str_replace(')', __('篇', 'kratos') . '</span></a>', $links);
    return $links;
}
add_filter('wp_list_categories', 'cat_count_span');

// 文章归档计数
function archive_count_span($links)
{
    $links = str_replace('</a>&nbsp;(', '<span> / ', $links);
    $links = str_replace(')', __('篇', 'kratos') . '</span></a>', $links);
    return $links;
}
add_filter('get_archives_link', 'archive_count_span');

// 小工具文章聚合 - 热点文章
function most_comm_posts($days = 30, $nums = 6)
{
    global $wpdb;

    $now_ts  = (int) current_time('timestamp', true);
    $today   = wp_date("Y-m-d H:i:s", $now_ts);
    $daysago = wp_date("Y-m-d H:i:s", $now_ts - ($days * DAY_IN_SECONDS));
    $result = $wpdb->get_results($wpdb->prepare("SELECT comment_count, ID, post_title, post_date FROM $wpdb->posts WHERE post_date BETWEEN %s AND %s and post_type = 'post' AND post_status = 'publish' ORDER BY comment_count DESC LIMIT 0, %d", $daysago, $today, $nums));
    $output = '';

    if (!empty($result)) {
        foreach ($result as $topten) {
            $postid = $topten->ID;
            $title = esc_attr(strip_tags($topten->post_title));
            $commentcount = $topten->comment_count;
            if ($commentcount >= 0) {
                $output .= '<a class="bookmark-item" title="' . $title . '" href="' . get_permalink($postid) . '" rel="bookmark"><i class="kicon i-book"></i>';
                $output .= $title;
                $output .= '</a>';
            }
        }
    }
    echo $output;
}

function timeago($time)
{
    $time = strtotime($time);
    $dtime = time() - $time;
    if ($dtime < 1) return __('刚刚', 'kratos');
    $intervals = [
        12 * 30 * 24 * 60 * 60 => __(' 年前', 'kratos'),
        30 * 24 * 60 * 60 => __(' 个月前', 'kratos'),
        7  * 24 * 60 * 60 => __(' 周前', 'kratos'),
        24 * 60 * 60 => __(' 天前', 'kratos'),
        60 * 60 => __(' 小时前', 'kratos'),
        60 => __(' 分钟前', 'kratos'),
        1 => __(' 秒前', 'kratos')
    ];
    foreach ($intervals as $sec => $str) {
        $v = $dtime / $sec;
        if ($v >= 1) return round($v) . $str;
    }
}

function string_cut($string, $sublen, $start = 0, $code = 'UTF-8')
{
    if ($code == 'UTF-8') {
        $pa = "/[\x01-\x7f]|[\xc2-\xdf][\x80-\xbf]|\xe0[\xa0-\xbf][\x80-\xbf]|[\xe1-\xef][\x80-\xbf][\x80-\xbf]|\xf0[\x90-\xbf][\x80-\xbf][\x80-\xbf]|[\xf1-\xf7][\x80-\xbf][\x80-\xbf][\x80-\xbf]/";
        preg_match_all($pa, $string, $t_string);
        if (count($t_string[0]) - $start > $sublen) return join('', array_slice($t_string[0], $start, $sublen)) . "...";
        return join('', array_slice($t_string[0], $start, $sublen));
    } else {
        $start = $start * 2;
        $sublen = $sublen * 2;
        $strlen = strlen($string);
        $tmpstr = '';
        for ($i = 0; $i < $strlen; $i++) {
            if ($i >= $start && $i < ($start + $sublen)) {
                if (ord(substr($string, $i, 1)) > 129) $tmpstr .= substr($string, $i, 2);
                else $tmpstr .= substr($string, $i, 1);
            }
            if (ord(substr($string, $i, 1)) > 129) $i++;
        }
        return $tmpstr;
    }
}

function latest_comments($list_number = 5, $cut_length = 50)
{
    global $wpdb, $output;
    $comments = $wpdb->get_results($wpdb->prepare("SELECT comment_ID, comment_post_ID, comment_author, comment_author_email, comment_date_gmt, comment_content FROM {$wpdb->comments} LEFT OUTER JOIN {$wpdb->posts} ON {$wpdb->comments}.comment_post_ID = {$wpdb->posts}.ID WHERE comment_approved = '1' AND (comment_type = '' OR comment_type = 'comment') AND post_password = '' ORDER BY comment_date_gmt DESC LIMIT %d", $list_number));
    foreach ($comments as $comment) {
        $nickname = esc_attr($comment->comment_author) ?: __('匿名', 'kratos');
        $output .= '<a href="' . get_the_permalink($comment->comment_post_ID) . '#commentform">
            <div class="meta clearfix">
                <div class="avatar float-start">' . get_avatar($comment, 60) . '</div>
                <div class="profile d-block">
                    <span class="date">' . $nickname . ' ' . __('发布于 ', 'kratos') . timeago($comment->comment_date_gmt) . '（' . wp_date(__('m月d日', 'kratos'), strtotime($comment->comment_date_gmt)) . '）</span>
                    <span class="message d-block">' . convert_smilies(esc_attr(string_cut(strip_tags($comment->comment_content), $cut_length))) . '</span>
                </div>
            </div>
        </a>';
    }
    return $output;
}

class widget_search extends WP_Widget
{

    public function __construct()
    {
        $widget_ops = array(
            'classname'                   => 'widget_search',
            'description'                 => __('A search form for your site.'),
            'customize_selective_refresh' => true,
        );
        parent::__construct('search', _x('Search', 'Search widget'), $widget_ops);
    }

    public function widget($args, $instance)
    {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $title = apply_filters('widget_title', $title, $instance, $this->id_base);

        echo '<div class="widget w-search">';
        if ($title) {
            echo '<div class="title">' . $title . '</div>';
        }
        echo '<div class="item"> <form role="search" method="get" id="searchform" class="searchform" action="' . home_url('/') . '"> <div class="input-group mt-2 mb-2"> <input type="text" name="s" id="search-widgets" class="form-control" placeholder="' . __('搜点什么呢?', 'kratos') . '"> <button class="btn btn-primary btn-search" type="submit" id="searchsubmit">' . __('搜索', 'kratos') . '</button> </div> </form>';
        echo '</div></div>';
    }

    public function form($instance)
    {
        $instance = wp_parse_args((array) $instance, array('title' => ''));
        $title    = $instance['title'];
?>
        <p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" /></label></p>
    <?php
    }

    public function update($new_instance, $old_instance)
    {
        $instance          = $old_instance;
        $new_instance      = wp_parse_args((array) $new_instance, array('title' => ''));
        $instance['title'] = sanitize_text_field($new_instance['title']);
        return $instance;
    }
}

class widget_ad extends WP_Widget
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', array($this, 'scripts'));

        $widget_ops = array(
            'name' => __('Kratos-plus - 图片广告', 'kratos'),
            'description' => __('显示自定义图片广告的工具', 'kratos'),
        );

        parent::__construct(false, false, $widget_ops);
    }

    public function scripts()
    {
        wp_enqueue_script('media-upload');
        wp_enqueue_media();
        wp_enqueue_script('widget_scripts', ASSET_PATH . '/assets/js/widget.min.js', array('jquery'));
        wp_enqueue_style('widget_css', ASSET_PATH . '/assets/css/widget.min.css', array());
    }

    public function widget($args, $instance)
    {
        $subtitle = !empty($instance['subtitle']) ? $instance['subtitle'] : __('广告', 'kratos');
        $image = !empty($instance['image']) ? $instance['image'] : '';
        $url = !empty($instance['url']) ? $instance['url'] : '';

        echo '<div class="widget w-ad">';
        echo '<a href="' . $url . '" target="_blank" rel="noreferrer"><img src="' . $image . '"><div class="prompt">' . $subtitle . '</div></a>';
        echo '</div>';
    }

    public function update($new_instance, $old_instance)
    {
        $instance = array();

        $instance['subtitle'] = (!empty($new_instance['subtitle'])) ? $new_instance['subtitle'] : '';
        $instance['image'] = (!empty($new_instance['image'])) ? $new_instance['image'] : '';
        $instance['url'] = (!empty($new_instance['url'])) ? $new_instance['url'] : '';

        return $instance;
    }

    public function form($instance)
    {
        $subtitle = !empty($instance['subtitle']) ? $instance['subtitle'] : __('广告', 'kratos');
        $image = !empty($instance['image']) ? $instance['image'] : '';
        $url = !empty($instance['url']) ? $instance['url'] : '';
    ?>
        <div class="media-widget-control">
            <p>
                <label for="<?php echo $this->get_field_id('subtitle'); ?>"><?php _e('副标题：', 'kratos'); ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id('subtitle'); ?>" name="<?php echo $this->get_field_name('subtitle'); ?>" type="text" value="<?php echo esc_attr($subtitle); ?>">
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('url'); ?>"><?php _e('链接地址：', 'kratos'); ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id('url'); ?>" name="<?php echo $this->get_field_name('url'); ?>" type="text" value="<?php echo esc_attr($url); ?>">
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('image'); ?>"><?php _e('广告图片:', 'kratos'); ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id('image'); ?>" name="<?php echo $this->get_field_name('image'); ?>" type="text" value="<?php echo esc_url($image); ?>" />
                <button type="button" class="button-update-media upload_ad"><?php _e('选择图片', 'kratos'); ?></button>
            </p>
        </div>
    <?php
    }
}

/**
 * 个人简介小工具（增强版）
 *
 * 增强点：
 * 1. 配置化：用户来源（WP 用户 / 自定义）、昵称/签名/头像 URL、标题、头像形状、点击行为、背景类型（图片/纯色/渐变/无）+ 遮罩浓度
 * 2. 社交入口：可增删的社交链接列表；图标复用主题 kicon 字体，或允许 Font Awesome class（参考 theme-featured-title.php 的按需入队）
 *    微信/QQ 支持二维码弹层
 * 3. 站点统计：文章 / 分类 / 标签 / 评论 计数，可分项开关，wp_cache 缓存 10 分钟
 * 4. 简介 Markdown：复用 PUC 内置的 Parsedown（inc/update-checker/vendor/Parsedown.php）
 * 5. CTA：最多两个自定义按钮
 * 6. 视觉：认证徽章 / 在线状态点 / 展开更多折叠 / 深色模式变量
 */
class widget_about extends WP_Widget
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', array($this, 'scripts'));
        add_action('wp_enqueue_scripts', array($this, 'front_scripts'));

        $widget_ops = array(
            'name'        => __('Kratos-plus - 个人简介', 'kratos'),
            'description' => __('站长个人简介的展示工具（支持社交、统计、Markdown、CTA、二维码）', 'kratos'),
        );

        parent::__construct(false, false, $widget_ops);
    }

    public function scripts()
    {
        wp_enqueue_script('media-upload');
        wp_enqueue_media();
        wp_enqueue_script('widget_scripts', ASSET_PATH . '/assets/js/widget.min.js', array('jquery'));
        wp_enqueue_style('widget_css', ASSET_PATH . '/assets/css/widget.min.css', array());
        wp_enqueue_style('widget_about_admin_css', ASSET_PATH . '/assets/css/widget.min.css', array(), THEME_VERSION);
    }

    // 前台按需加载 FA CSS（当社交链接里存在 fa 图标 class 时才加载）
    public function front_scripts()
    {
        if (!is_active_widget(false, false, $this->id_base)) {
            return;
        }
        if (wp_style_is('fontawesome', 'enqueued') || wp_style_is('fontawesome', 'registered')) {
            return;
        }
        $settings = $this->get_settings();
        if (!is_array($settings)) return;
        foreach ($settings as $instance) {
            if (empty($instance['socials']) || !is_array($instance['socials'])) continue;
            foreach ($instance['socials'] as $s) {
                if (!empty($s['icon']) && stripos($s['icon'], 'fa') !== false && stripos($s['icon'], ' ') !== false) {
                    wp_enqueue_style('fontawesome', get_template_directory_uri() . '/assets/css/fontawesome.min.css', array(), FA_VERSION);
                    return;
                }
            }
        }
    }

    private function defaults()
    {
        return array(
            'introduce'      => '',
            'slogan'         => '',
            'background'     => '',
            'collapse_at'    => 0,        // 0=不折叠；>0 字数阈值
            'show_stats'     => 1,
            'stat_posts'     => 1,
            'stat_cats'      => 1,
            'stat_tags'      => 1,
            'stat_comments'  => 1,
            'socials'        => array(),  // list of {icon,label,url,newtab,qrcode}
            'cta1_text'      => '',
            'cta1_url'       => '',
            'cta1_style'     => 'primary',
            'cta2_text'      => '',
            'cta2_url'       => '',
            'cta2_style'     => 'ghost',
        );
    }

    /** 数字缩写 1200 -> 1.2k */
    private function format_count($n)
    {
        $n = (int) $n;
        if ($n >= 10000) return round($n / 10000, 1) . 'w';
        if ($n >= 1000)  return round($n / 1000, 1) . 'k';
        return (string) $n;
    }

    /** 站点计数（缓存 10 分钟） */
    private function get_site_stats()
    {
        $cache = wp_cache_get('kratos_about_stats', 'widget_about');
        if ($cache !== false) return $cache;
        $posts = wp_count_posts();
        $stats = array(
            'posts'    => isset($posts->publish) ? (int) $posts->publish : 0,
            'cats'     => (int) wp_count_terms(array('taxonomy' => 'category', 'hide_empty' => true)),
            'tags'     => (int) wp_count_terms(array('taxonomy' => 'post_tag', 'hide_empty' => true)),
            'comments' => (int) get_comments(array('count' => true, 'status' => 'approve')),
        );
        wp_cache_set('kratos_about_stats', $stats, 'widget_about', 600);
        return $stats;
    }

    public function widget($args, $instance)
    {
        $i = wp_parse_args((array) $instance, $this->defaults());

        // 昵称/头像固定取站长（用户 ID = 1），简介优先自定义再回退用户资料
        $uid = 1;
        $username  = get_the_author_meta('display_name', $uid);
        $avatar    = get_avatar_url($uid, array('size' => 300));
        $introduce = trim((string) $i['introduce']);
        if ($introduce === '') {
            $introduce = (string) get_the_author_meta('description', $uid);
        }
        if ($introduce === '') {
            $introduce = __('这个人很懒，什么都没留下', 'kratos');
        }

        // 背景图（固定使用图片模式）
        $bg_url = $i['background'] !== '' ? $i['background'] : ASSET_PATH . '/assets/img/about-background.png';
        $bg_style = "background:url('" . esc_url($bg_url) . "') no-repeat center center;background-size:cover;";

        // 头像点击（沿用主题选项 g_login 决定是否可点跳登录）
        $avatar_link_open  = '';
        $avatar_link_close = '';
        if (kratos_option('g_login', true)) {
            $href = current_user_can('manage_options') ? admin_url() : wp_login_url();
            $avatar_link_open  = '<a href="' . esc_url($href) . '">';
            $avatar_link_close = '</a>';
        }

        // 简介渲染：纯文本，保留换行
        $introduce_html = '<p>' . nl2br(esc_html($introduce)) . '</p>';
        $collapse_at = (int) $i['collapse_at'];
        $collapsed = ($collapse_at > 0 && mb_strlen(wp_strip_all_tags($introduce)) > $collapse_at);

        echo '<div class="widget w-about w-about-plus">';

        // 头部：背景
        echo '<div class="w-about-header" style="' . $bg_style . '"></div>';

        // 头像 wrapper
        echo '<div class="wrapper text-center w-about-wrapper">';
        echo $avatar_link_open;
        echo '<span class="w-about-avatar is-circle is-hoverable">';
        echo '<img src="' . esc_url($avatar) . '" alt="' . esc_attr($username) . '">';
        echo '</span>';
        echo $avatar_link_close;
        echo '</div>';

        // 文本区
        echo '<div class="textwidget text-center w-about-body">';
        echo '<p class="username">' . esc_html($username) . '</p>';
        if (!empty($i['slogan'])) {
            echo '<p class="slogan">' . esc_html($i['slogan']) . '</p>';
        }
        if ($collapsed) {
            echo '<div class="about is-collapsible" data-collapse="1">';
            echo '<div class="about-inner">' . $introduce_html . '</div>';
            echo '<button type="button" class="about-toggle" aria-expanded="false">' . esc_html__('展开更多', 'kratos') . '</button>';
            echo '</div>';
        } else {
            echo '<div class="about">' . $introduce_html . '</div>';
        }

        // 统计条
        if (!empty($i['show_stats'])) {
            $stats = $this->get_site_stats();
            $items = array();
            if (!empty($i['stat_posts']))    $items[] = array('n' => $stats['posts'],    'label' => __('文章', 'kratos'),   'url' => get_post_type_archive_link('post') ?: home_url('/'));
            if (!empty($i['stat_cats']))     $items[] = array('n' => $stats['cats'],     'label' => __('分类', 'kratos'),   'url' => '');
            if (!empty($i['stat_tags']))     $items[] = array('n' => $stats['tags'],     'label' => __('标签', 'kratos'),   'url' => '');
            if (!empty($i['stat_comments'])) $items[] = array('n' => $stats['comments'], 'label' => __('评论', 'kratos'),   'url' => '');
            if ($items) {
                echo '<div class="w-about-stats">';
                foreach ($items as $it) {
                    $inner = '<span class="w-about-stat-num">' . esc_html($this->format_count($it['n'])) . '</span><span class="w-about-stat-label">' . esc_html($it['label']) . '</span>';
                    if ($it['url']) echo '<a class="w-about-stat" href="' . esc_url($it['url']) . '">' . $inner . '</a>';
                    else echo '<span class="w-about-stat">' . $inner . '</span>';
                }
                echo '</div>';
            }
        }

        // 社交入口
        if (!empty($i['socials']) && is_array($i['socials'])) {
            echo '<div class="w-about-socials">';
            foreach ($i['socials'] as $idx => $s) {
                $icon   = isset($s['icon']) ? trim($s['icon']) : '';
                $label  = isset($s['label']) ? $s['label'] : '';
                $url    = isset($s['url']) ? $s['url'] : '';
                $newtab = !empty($s['newtab']);
                $qr     = isset($s['qrcode']) ? $s['qrcode'] : '';
                if ($icon === '' && $label === '' && $url === '' && $qr === '') continue;

                // 图标节点：kicon（i-xxx）或 fa（含空格视为 FA class）
                $icon_html = '';
                if ($icon !== '') {
                    if (strpos($icon, 'i-') === 0) {
                        $icon_html = '<i class="kicon ' . esc_attr($icon) . '"></i>';
                    } elseif (strpos($icon, ' ') !== false) {
                        $icon_html = '<i class="' . esc_attr($icon) . '"></i>';
                    } else {
                        $icon_html = '<i class="kicon i-' . esc_attr(ltrim($icon, 'i-')) . '"></i>';
                    }
                } else {
                    $icon_html = '<span class="w-about-social-letter">' . esc_html(mb_substr($label !== '' ? $label : '?', 0, 1, 'UTF-8')) . '</span>';
                }

                $tip = $label !== '' ? $label : $url;

                if ($qr !== '') {
                    echo '<span class="w-about-social has-qr" tabindex="0" title="' . esc_attr($tip) . '">';
                    echo $icon_html;
                    echo '<span class="w-about-qr-pop"><img src="' . esc_url($qr) . '" alt=""><span class="w-about-qr-label">' . esc_html($label) . '</span></span>';
                    echo '</span>';
                } elseif ($url !== '') {
                    $target = $newtab ? ' target="_blank" rel="noopener noreferrer"' : '';
                    echo '<a class="w-about-social" href="' . esc_url($url) . '"' . $target . ' title="' . esc_attr($tip) . '" aria-label="' . esc_attr($tip) . '">' . $icon_html . '</a>';
                }
            }
            echo '</div>';
        }

        // CTA
        $ctas = array();
        for ($k = 1; $k <= 2; $k++) {
            $t = $i["cta{$k}_text"];
            $u = $i["cta{$k}_url"];
            $st = $i["cta{$k}_style"];
            if ($t !== '' && $u !== '') $ctas[] = array('text' => $t, 'url' => $u, 'style' => $st);
        }
        if ($ctas) {
            echo '<div class="w-about-cta">';
            foreach ($ctas as $c) {
                echo '<a class="w-about-cta-btn is-' . esc_attr($c['style']) . '" href="' . esc_url($c['url']) . '">' . esc_html($c['text']) . '</a>';
            }
            echo '</div>';
        }

        echo '</div>'; // .textwidget
        echo '</div>'; // .widget

        // 内联极简 JS：折叠 + 二维码弹层（无 jQuery 依赖）
        static $inline_printed = false;
        if (!$inline_printed) {
            $inline_printed = true;
            echo <<<HTML
<script>(function(){
  document.addEventListener('click',function(e){
    var t=e.target;
    if(t&&t.classList&&t.classList.contains('about-toggle')){
      var box=t.parentNode;var open=box.classList.toggle('is-open');
      t.setAttribute('aria-expanded',open?'true':'false');
      t.textContent=open?'收起':'展开更多';
    }
  });
})();</script>
HTML;
        }
    }

    public function update($new_instance, $old_instance)
    {
        $d = $this->defaults();
        $out = wp_parse_args((array) $old_instance, $d);
        // 表单中保留的三个字段
        $out['slogan']    = sanitize_text_field($new_instance['slogan'] ?? '');
        $out['introduce'] = wp_kses_post($new_instance['introduce'] ?? '');
        $out['background']= esc_url_raw($new_instance['background'] ?? '');
        $out['collapse_at']   = max(0, (int) ($new_instance['collapse_at'] ?? 0));
        $out['show_stats']    = !empty($new_instance['show_stats']) ? 1 : 0;
        $out['stat_posts']    = !empty($new_instance['stat_posts']) ? 1 : 0;
        $out['stat_cats']     = !empty($new_instance['stat_cats']) ? 1 : 0;
        $out['stat_tags']     = !empty($new_instance['stat_tags']) ? 1 : 0;
        $out['stat_comments'] = !empty($new_instance['stat_comments']) ? 1 : 0;

        // 社交列表：并列数组 socials_icon[] / socials_label[] / socials_url[] / socials_newtab[] / socials_qrcode[]
        $out['socials'] = array();
        $icons        = (array) ($new_instance['socials_icon']        ?? array());
        $icons_custom = (array) ($new_instance['socials_icon_custom'] ?? array());
        $labels = (array) ($new_instance['socials_label']  ?? array());
        $urls   = (array) ($new_instance['socials_url']    ?? array());
        $newtab = (array) ($new_instance['socials_newtab'] ?? array());
        $qrs    = (array) ($new_instance['socials_qrcode'] ?? array());
        $count  = max(count($icons), count($labels), count($urls), count($qrs));
        for ($k = 0; $k < $count; $k++) {
            $custom = sanitize_text_field($icons_custom[$k] ?? '');
            $icon  = $custom !== '' ? $custom : sanitize_text_field($icons[$k]  ?? '');
            $label = sanitize_text_field($labels[$k] ?? '');
            $url   = esc_url_raw($urls[$k] ?? '');
            $qr    = esc_url_raw($qrs[$k] ?? '');
            $nb    = !empty($newtab[$k]) ? 1 : 0;
            if ($icon === '' && $label === '' && $url === '' && $qr === '') continue;
            $out['socials'][] = array('icon' => $icon, 'label' => $label, 'url' => $url, 'newtab' => $nb, 'qrcode' => $qr);
        }

        foreach (array(1, 2) as $k) {
            $out["cta{$k}_text"]  = sanitize_text_field($new_instance["cta{$k}_text"]  ?? '');
            $out["cta{$k}_url"]   = esc_url_raw($new_instance["cta{$k}_url"] ?? '');
            $out["cta{$k}_style"] = in_array($new_instance["cta{$k}_style"] ?? 'primary', array('primary','ghost'), true) ? $new_instance["cta{$k}_style"] : 'primary';
        }

        wp_cache_delete('kratos_about_stats', 'widget_about');
        return $out;
    }

    public function form($instance)
    {
        $i = wp_parse_args((array) $instance, $this->defaults());
        $fnm = function ($k) { return $this->get_field_name($k); };
        $kicons = array('i-github','i-gitee','i-sina','i-twitter','i-youtube','i-bilibili','i-douban','i-stackflow','i-coding','i-linkedin','i-telegram','i-wechat','i-email','i-cemail','i-user','i-author','i-url','i-like','i-donate','i-book','i-comments');
        $wid = $this->id;

        // 渲染一个社交行
        $render_row = function ($idx, $s) use ($kicons) {
            $s = wp_parse_args((array) $s, array('icon'=>'','label'=>'','url'=>'','newtab'=>1,'qrcode'=>''));
            ob_start(); ?>
            <div class="wab-social">
                <div class="wab-r">
                    <select name="<?php echo esc_attr($this->get_field_name('socials_icon')); ?>[]">
                        <option value=""><?php _e('图标', 'kratos'); ?></option>
                        <?php foreach ($kicons as $ic): ?>
                            <option value="<?php echo esc_attr($ic); ?>" <?php selected($s['icon'], $ic); ?>><?php echo esc_html($ic); ?></option>
                        <?php endforeach; ?>
                        <?php if ($s['icon'] !== '' && !in_array($s['icon'], $kicons, true)): ?>
                            <option value="<?php echo esc_attr($s['icon']); ?>" selected><?php echo esc_html($s['icon']); ?></option>
                        <?php endif; ?>
                    </select>
                    <input type="text" name="<?php echo esc_attr($this->get_field_name('socials_label')); ?>[]" placeholder="<?php esc_attr_e('名称', 'kratos'); ?>" value="<?php echo esc_attr($s['label']); ?>">
                    <button type="button" class="button-link wab-remove" title="<?php esc_attr_e('删除', 'kratos'); ?>">×</button>
                </div>
                <div class="wab-r">
                    <input type="text" name="<?php echo esc_attr($this->get_field_name('socials_url')); ?>[]" placeholder="<?php esc_attr_e('链接（http… / mailto:）', 'kratos'); ?>" value="<?php echo esc_attr($s['url']); ?>">
                    <label class="wab-nt"><input type="checkbox" name="<?php echo esc_attr($this->get_field_name('socials_newtab')); ?>[<?php echo (int) $idx; ?>]" value="1" <?php checked(!empty($s['newtab'])); ?>> <?php _e('新窗口', 'kratos'); ?></label>
                </div>
                <div class="wab-r">
                    <input type="text" name="<?php echo esc_attr($this->get_field_name('socials_qrcode')); ?>[]" placeholder="<?php esc_attr_e('二维码 URL（可选，有则覆盖链接）', 'kratos'); ?>" value="<?php echo esc_attr($s['qrcode']); ?>">
                </div>
                <input type="hidden" name="<?php echo esc_attr($this->get_field_name('socials_icon_custom')); ?>[]" value="">
            </div>
            <?php return ob_get_clean();
        };
        ?>
        <style>
            .wab-form details{margin:6px 0;border:1px solid #dcdcde;border-radius:4px;background:#fff}
            .wab-form details[open]{background:#fafafa}
            .wab-form summary{padding:8px 10px;cursor:pointer;font-weight:600;font-size:13px;color:#1d2327;list-style:none;user-select:none}
            .wab-form summary::-webkit-details-marker{display:none}
            .wab-form summary::before{content:"▸";display:inline-block;margin-right:6px;transition:transform .15s;color:#8a8f99}
            .wab-form details[open] summary::before{transform:rotate(90deg)}
            .wab-form .wab-body{padding:4px 10px 10px}
            .wab-form .wab-body p{margin:6px 0}
            .wab-form .wab-body label{display:block;font-size:12px;color:#50575e;margin-bottom:2px}
            .wab-form .wab-inline{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
            .wab-form .wab-inline > *{flex:0 0 auto}
            .wab-form .wab-inline .widefat{flex:1;min-width:0}
            .wab-form .wab-media{display:flex;gap:6px;align-items:center}
            .wab-form .wab-media input{flex:1 1 auto;min-width:0;width:100%}
            .wab-form .wab-media .button{flex:0 0 auto;width:auto;padding:0 6px;min-height:26px;line-height:24px;font-size:11px}
            .wab-form .wab-checks{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:12px}
            .wab-form .wab-checks label{display:inline-flex;align-items:center;gap:4px;margin:0;color:#1d2327}
            .wab-form .wab-parent{margin:8px 0 4px}
            .wab-form .wab-children{margin:0 0 6px;padding:6px 8px 6px 24px;border-left:2px solid #dcdcde;background:#f6f7f7;border-radius:0 4px 4px 0;position:relative}
            .wab-form .wab-children::before{content:"";position:absolute;left:-2px;top:-4px;width:14px;height:10px;border-left:2px solid #dcdcde;border-bottom:2px solid #dcdcde;border-radius:0 0 0 4px}
            .wab-form .wab-children label{font-size:12px;color:#50575e}
            .wab-social{border:1px solid #dcdcde;background:#fff;border-radius:4px;padding:6px 8px;margin-bottom:6px;position:relative}
            .wab-social .wab-r{display:flex;gap:6px;align-items:center;margin-bottom:4px}
            .wab-social .wab-r > input[type=text]{flex:1;min-width:0}
            .wab-social .wab-r > select{max-width:120px}
            .wab-social .wab-nt{flex:0 0 auto;font-size:12px;color:#50575e}
            .wab-social .wab-remove{color:#b32d2e;font-size:18px;line-height:1;padding:0 4px;text-decoration:none}
            .wab-add{margin-top:4px}
            .wab-form .wab-swatches{display:flex;gap:6px;align-items:center}
            .wab-form .wab-swatches label{display:flex;flex-direction:column;font-size:11px;color:#8a8f99;align-items:center}
        </style>

        <div class="wab-form" data-widget-id="<?php echo esc_attr($wid); ?>">

        <details open>
            <summary><?php _e('基础', 'kratos'); ?></summary>
            <div class="wab-body">
                <p><input class="widefat" type="text" name="<?php echo $fnm('slogan'); ?>" placeholder="<?php esc_attr_e('一句话签名（可留空）', 'kratos'); ?>" value="<?php echo esc_attr($i['slogan']); ?>"></p>
                <p><textarea class="widefat" rows="4" name="<?php echo $fnm('introduce'); ?>" placeholder="<?php esc_attr_e('个人简介（留空则使用用户资料的简介）', 'kratos'); ?>"><?php echo esc_textarea($i['introduce']); ?></textarea></p>
                <p class="wab-media">
                    <input type="text" name="<?php echo $fnm('background'); ?>" placeholder="<?php esc_attr_e('背景图 URL（留空使用默认）', 'kratos'); ?>" value="<?php echo esc_attr($i['background']); ?>">
                    <button type="button" class="button button-update-media upload_background"><?php _e('选择', 'kratos'); ?></button>
                </p>
            </div>
        </details>

        <details>
            <summary><?php _e('模块开关', 'kratos'); ?></summary>
            <div class="wab-body">
                <p class="wab-checks">
                    <label><?php _e('简介折叠字数（0=不折叠）', 'kratos'); ?> <input type="number" min="0" step="1" name="<?php echo $fnm('collapse_at'); ?>" value="<?php echo esc_attr($i['collapse_at']); ?>" style="width:70px"></label>
                </p>
                <p class="wab-checks wab-parent">
                    <label><input type="checkbox" value="1" name="<?php echo $fnm('show_stats'); ?>" <?php checked($i['show_stats'],1); ?>> <strong><?php _e('统计条', 'kratos'); ?></strong></label>
                </p>
                <p class="wab-checks wab-children">
                    <label><input type="checkbox" value="1" name="<?php echo $fnm('stat_posts'); ?>" <?php checked($i['stat_posts'],1); ?>> <?php _e('文章', 'kratos'); ?></label>
                    <label><input type="checkbox" value="1" name="<?php echo $fnm('stat_cats'); ?>" <?php checked($i['stat_cats'],1); ?>> <?php _e('分类', 'kratos'); ?></label>
                    <label><input type="checkbox" value="1" name="<?php echo $fnm('stat_tags'); ?>" <?php checked($i['stat_tags'],1); ?>> <?php _e('标签', 'kratos'); ?></label>
                    <label><input type="checkbox" value="1" name="<?php echo $fnm('stat_comments'); ?>" <?php checked($i['stat_comments'],1); ?>> <?php _e('评论', 'kratos'); ?></label>
                </p>
            </div>
        </details>

        <details>
            <summary><?php _e('社交入口', 'kratos'); ?></summary>
            <div class="wab-body">
                <div class="wab-socials-list">
                    <?php
                    $socials = !empty($i['socials']) && is_array($i['socials']) ? $i['socials'] : array();
                    if (empty($socials)) $socials = array(array('icon'=>'','label'=>'','url'=>'','newtab'=>1,'qrcode'=>''));
                    foreach ($socials as $idx => $s) echo $render_row($idx, $s);
                    ?>
                </div>
                <p class="wab-add"><button type="button" class="button button-secondary wab-add-btn">+ <?php _e('添加一项', 'kratos'); ?></button></p>
                <p class="description" style="margin:2px 0 0"><?php _e('图标：主题内置 kicon（i-github/i-wechat…）', 'kratos'); ?></p>
                <template class="wab-social-tpl"><?php echo $render_row(9999, array('icon'=>'','label'=>'','url'=>'','newtab'=>1,'qrcode'=>'')); ?></template>
            </div>
        </details>

        <details>
            <summary><?php _e('CTA 按钮', 'kratos'); ?></summary>
            <div class="wab-body">
                <?php foreach (array(1, 2) as $k): ?>
                <p class="wab-inline">
                    <input type="text" name="<?php echo $fnm("cta{$k}_text"); ?>" placeholder="<?php printf(esc_attr__('按钮 %d 文字', 'kratos'), $k); ?>" value="<?php echo esc_attr($i["cta{$k}_text"]); ?>" style="flex:1;min-width:0">
                    <select name="<?php echo $fnm("cta{$k}_style"); ?>">
                        <option value="primary" <?php selected($i["cta{$k}_style"],'primary'); ?>><?php _e('实心', 'kratos'); ?></option>
                        <option value="ghost" <?php selected($i["cta{$k}_style"],'ghost'); ?>><?php _e('描边', 'kratos'); ?></option>
                    </select>
                </p>
                <p><input class="widefat" type="text" name="<?php echo $fnm("cta{$k}_url"); ?>" placeholder="<?php esc_attr_e('链接（http… / mailto:）', 'kratos'); ?>" value="<?php echo esc_attr($i["cta{$k}_url"]); ?>"></p>
                <?php endforeach; ?>
            </div>
        </details>

        </div>

        <script>
        (function(){
            var root=document.currentScript && document.currentScript.previousElementSibling;
            if(!root || !root.classList || !root.classList.contains('wab-form')) return;
            if(root.dataset.wabInit) return; root.dataset.wabInit='1';
            var syncChildren=function(){
                var parent=root.querySelector('.wab-parent input[type=checkbox]');
                var kids=root.querySelectorAll('.wab-children input[type=checkbox]');
                if(!parent) return;
                kids.forEach(function(cb){cb.disabled=!parent.checked;});
                var box=root.querySelector('.wab-children');
                if(box) box.style.opacity=parent.checked?'1':'0.5';
            };
            syncChildren();
            root.addEventListener('change', function(e){
                if(e.target.matches('.wab-parent input[type=checkbox]')) syncChildren();
            });
            root.addEventListener('click', function(e){
                var t=e.target;
                if(t.classList.contains('wab-add-btn')){
                    var tpl=root.querySelector('template.wab-social-tpl');
                    var list=root.querySelector('.wab-socials-list');
                    if(tpl && list){
                        var frag=document.createElement('div');
                        frag.innerHTML=tpl.innerHTML.trim();
                        list.appendChild(frag.firstChild);
                    }
                } else if(t.classList.contains('wab-remove')){
                    e.preventDefault();
                    var row=t.closest('.wab-social');
                    if(row) row.parentNode.removeChild(row);
                }
            });
        })();
        </script>
    <?php
    }
}

class widget_tags extends WP_Widget
{
    public function __construct()
    {
        $widget_ops = array(
            'name' => __('Kratos-plus - 标签聚合', 'kratos'),
            'description' => __('文章标签的展示工具', 'kratos'),
        );

        parent::__construct(false, false, $widget_ops);
    }

    public function widget($args, $instance)
    {
        $number = !empty($instance['number']) ? $instance['number'] : '8';
        $order = !empty($instance['order']) ? $instance['order'] : 'RAND';
        $tags = wp_tag_cloud(
            array(
                'unit' => 'px',
                'smallest' => 14,
                'largest' => 14,
                'number' => $number,
                'format' => 'flat',
                'orderby' => 'count',
                'order' => $order,
                'echo' => false,
            )
        );
        echo '<div class="widget w-tags">';
        echo '<div class="title">' . __('标签聚合', 'kratos') . '</div>';
        echo '<div class="item">' . $tags . '</div>';
        echo '</div>';
    }

    public function update($new_instance, $old_instance)
    {
        $instance = array();

        $instance['number'] = (!empty($new_instance['number'])) ? $new_instance['number'] : '';
        $instance['order'] = (!empty($new_instance['order'])) ? $new_instance['order'] : '';

        return $instance;
    }

    public function form($instance)
    {
        global $wpdb;
        $number = !empty($instance['number']) ? $instance['number'] : '8';
        $order = !empty($instance['order']) ? $instance['order'] : 'RAND';
    ?>
        <div class="media-widget-control">
            <p>
                <label for="<?php echo $this->get_field_id('number'); ?>"><?php _e('显示数量：', 'kratos'); ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id('number'); ?>" name="<?php echo $this->get_field_name('number'); ?>" type="text" value="<?php echo esc_attr($number); ?>" />
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('order'); ?>"><?php _e('显示排序：', 'kratos'); ?></label>
                <select name="<?php echo $this->get_field_name("order"); ?>" id='<?php echo $this->get_field_id("order"); ?>'>
                    <option value="DESC" <?php echo ($order == 'DESC') ? 'selected' : ''; ?>><?php _e('降序', 'kratos'); ?></option>
                    <option value="ASC" <?php echo ($order == 'ASC') ? 'selected' : ''; ?>><?php _e('升序', 'kratos'); ?></option>
                    <option value="RAND" <?php echo ($order == 'RAND') ? 'selected' : ''; ?>><?php _e('随机', 'kratos'); ?></option>
                </select>
            </p>
        </div>
    <?php
    }
}

class widget_posts extends WP_Widget
{
    public function __construct()
    {
        $widget_ops = array(
            'name' => __('Kratos-plus - 文章聚合', 'kratos'),
            'description' => __('展示最热、随机、最新文章的工具', 'kratos'),
        );

        parent::__construct(false, false, $widget_ops);
    }

    public function widget($args, $instance)
    {
        $number = !empty($instance['number']) ? $instance['number'] : '6';
        $days = !empty($instance['days']) ? $instance['days'] : '30';
        $order = !empty($instance['order']) ? $instance['order'] : 'hot';

        echo '<div class="widget w-recommended">';
    ?>
        <div class="nav nav-tabs d-none d-xl-flex" id="nav-tab" role="tablist">
            <a class="nav-item nav-link <?php echo $active = ($order == 'new') ? 'active' : null; ?>" id="nav-new-tab" data-bs-toggle="tab" href="#nav-new" role="tab" aria-controls="nav-new" aria-selected="<?php echo $selected = ($order == 'new') ? 'true' : 'false'; ?>"><i class="kicon i-tabnew"></i><?php _e('最新', 'kratos'); ?></a>
            <a class="nav-item nav-link <?php echo $active = ($order == 'hot') ? 'active' : null; ?>" id="nav-hot-tab" data-bs-toggle="tab" href="#nav-hot" role="tab" aria-controls="nav-hot" aria-selected="<?php echo $selected = ($order == 'hot') ? 'true' : 'false'; ?>"><i class="kicon i-tabhot"></i><?php _e('热点', 'kratos'); ?></a>
            <a class="nav-item nav-link <?php echo $active = ($order == 'random') ? 'active' : null; ?>" id="nav-random-tab" data-bs-toggle="tab" href="#nav-random" role="tab" aria-controls="nav-random" aria-selected="<?php echo $selected = ($order == 'random') ? 'true' : 'false'; ?>"><i class="kicon i-tabrandom"></i><?php _e('随机', 'kratos'); ?></a>
        </div>
        <div class="nav nav-tabs d-xl-none" id="nav-tab" role="tablist">
            <a class="nav-item nav-link <?php echo $active = ($order == 'new') ? 'active' : null; ?>" id="nav-new-tab" data-bs-toggle="tab" href="#nav-new" role="tab" aria-controls="nav-new" aria-selected="<?php echo $selected = ($order == 'new') ? 'true' : 'false'; ?>"><?php _e('最新', 'kratos'); ?></a>
            <a class="nav-item nav-link <?php echo $active = ($order == 'hot') ? 'active' : null; ?>" id="nav-hot-tab" data-bs-toggle="tab" href="#nav-hot" role="tab" aria-controls="nav-hot" aria-selected="<?php echo $selected = ($order == 'hot') ? 'true' : 'false'; ?>"><?php _e('热点', 'kratos'); ?></a>
            <a class="nav-item nav-link <?php echo $active = ($order == 'random') ? 'active' : null; ?>" id="nav-random-tab" data-bs-toggle="tab" href="#nav-random" role="tab" aria-controls="nav-random" aria-selected="<?php echo $selected = ($order == 'random') ? 'true' : 'false'; ?>"><?php _e('随机', 'kratos'); ?></a>
        </div>
        <div class="tab-content" id="nav-tabContent">
            <div class="tab-pane fade <?php echo $active = ($order == 'new') ? 'show active' : null; ?>" id="nav-new" role="tabpanel" aria-labelledby="nav-new-tab">
                <?php $myposts = get_posts('numberposts=' . $number . ' & offset=0');
                foreach ($myposts as $post) : ?>
                    <a class="bookmark-item" rel="bookmark" title="<?php echo esc_attr(strip_tags($post->post_title)); ?>" href="<?php echo get_permalink($post->ID); ?>"><i class="kicon i-book"></i><?php echo esc_attr(strip_tags($post->post_title)); ?></a>
                <?php endforeach; ?>
            </div>
            <div class="tab-pane fade <?php echo $active = ($order == 'hot') ? 'show active' : null; ?>" id="nav-hot" role="tabpanel" aria-labelledby="nav-hot-tab">
                <?php if (function_exists('most_comm_posts')) {
                    most_comm_posts($days, $number);
                } ?>
            </div>
            <div class="tab-pane fade <?php echo $active = ($order == 'random') ? 'show active' : null; ?>" id="nav-random" role="tabpanel" aria-labelledby="nav-random-tab">
                <?php $myposts = get_posts('numberposts=' . $number . ' & offset=0 & orderby=rand');
                foreach ($myposts as $post) : ?>
                    <a class="bookmark-item" rel="bookmark" title="<?php echo esc_attr(strip_tags($post->post_title)); ?>" href="<?php echo get_permalink($post->ID); ?>"><i class="kicon i-book"></i><?php echo esc_attr(strip_tags($post->post_title)); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php echo '</div>';
    }

    public function update($new_instance, $old_instance)
    {
        $instance = array();

        $instance['number'] = (!empty($new_instance['number'])) ? $new_instance['number'] : '';
        $instance['days'] = (!empty($new_instance['days'])) ? $new_instance['days'] : '';
        $instance['order'] = (!empty($new_instance['order'])) ? $new_instance['order'] : '';

        return $instance;
    }
    public function form($instance)
    {
        global $wpdb;
        $number = !empty($instance['number']) ? $instance['number'] : '6';
        $days = !empty($instance['days']) ? $instance['days'] : '30';
        $order = !empty($instance['order']) ? $instance['order'] : 'hot';
    ?>
        <div class="media-widget-control">
            <p>
                <label for="<?php echo $this->get_field_id('number'); ?>"><?php _e('展示数量：', 'kratos'); ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id('number'); ?>" name="<?php echo $this->get_field_name('number'); ?>" type="text" value="<?php echo esc_attr($number); ?>" />
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('days'); ?>"><?php _e('统计天数：', 'kratos'); ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id('days'); ?>" name="<?php echo $this->get_field_name('days'); ?>" type="text" value="<?php echo esc_attr($days); ?>" />
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('order'); ?>"><?php _e('默认显示：', 'kratos'); ?></label>
                <select name="<?php echo $this->get_field_name("order"); ?>" id='<?php echo $this->get_field_id("order"); ?>'>
                    <option value="new" <?php echo ($order == 'new') ? 'selected' : ''; ?>><?php _e('最新', 'kratos'); ?></option>
                    <option value="hot" <?php echo ($order == 'hot') ? 'selected' : ''; ?>><?php _e('热点', 'kratos'); ?></option>
                    <option value="random" <?php echo ($order == 'random') ? 'selected' : ''; ?>><?php _e('随机', 'kratos'); ?></option>
                </select>
            </p>
        </div>
    <?php
    }
}

class widget_comments extends WP_Widget
{
    public function __construct()
    {
        $widget_ops = array(
            'name' => __('Kratos-plus - 最近评论', 'kratos'),
            'description' => __('展示站点最近的评论', 'kratos'),
        );

        parent::__construct(false, false, $widget_ops);
    }

    public function widget($args, $instance)
    {
        $number = !empty($instance['number']) ? $instance['number'] : '5';
        $title = !empty($instance['title']) ? $instance['title'] : __('最近评论', 'kratos');

        echo '<div class="widget w-comments"><div class="title">' . $title . '</div><div class="comments">';
        echo latest_comments($number, 50);
        echo '</div></div>';
    }

    public function update($new_instance, $old_instance)
    {
        $instance = array();

        $instance['number'] = (!empty($new_instance['number'])) ? $new_instance['number'] : '';
        $instance['title'] = (!empty($new_instance['title'])) ? $new_instance['title'] : '';

        return $instance;
    }
    public function form($instance)
    {
        global $wpdb;
        $number = !empty($instance['number']) ? $instance['number'] : '5';
        $title = !empty($instance['title']) ? $instance['title'] : __('最近评论', 'kratos');
    ?>
        <div class="media-widget-control">
            <p>
                <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('栏目标题：', 'kratos'); ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" />
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('number'); ?>"><?php _e('展示数量：', 'kratos'); ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id('number'); ?>" name="<?php echo $this->get_field_name('number'); ?>" type="text" value="<?php echo esc_attr($number); ?>" />
            </p>
        </div>
<?php
    }
}

class widget_toc extends WP_Widget
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', array($this, 'scripts'));

        $widget_ops = array(
            'name' => __('Kratos-plus - 文章目录', 'kratos'),
            'description' => __('仅在有目录规则的文章中显示目录的工具', 'kratos'),
        );

        parent::__construct(false, false, $widget_ops);
    }

    public function scripts()
    {
        wp_enqueue_script('media-upload');
        wp_enqueue_media();
        wp_enqueue_script('widget_scripts', ASSET_PATH . '/assets/js/widget.min.js', array('jquery'));
        wp_enqueue_style('widget_css', ASSET_PATH . '/assets/css/widget.min.css', array());
    }

    public function widget($args, $instance)
    {
        // 只在有正文内容的单篇页面输出空壳，具体目录由前端 JS 扫描正文标题生成。
        // 这样整页缓存 / 对象缓存不会把 A 文章的目录带到 B 文章上。
        if (!is_singular()) return;

        $collapsed = !empty($instance['collapsed']);

        echo '<div class="widget w-toc is-empty"'
            . ' data-toc-target=".k-main .article .content"'
            . ' data-toc-collapsed="' . ($collapsed ? '1' : '0') . '">'
            . '<div class="title" role="button" tabindex="0">' . __('文章目录', 'kratos') . '</div>'
            . '<div class="item"></div>'
            . '</div>';
    }

    public function form($instance)
    {
        $instance  = wp_parse_args((array) $instance, array('collapsed' => 0));
        $collapsed = !empty($instance['collapsed']);
        $id        = $this->get_field_id('collapsed');
        $name      = $this->get_field_name('collapsed');
?>
        <p>
            <input class="checkbox" type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($collapsed); ?> />
            <label for="<?php echo esc_attr($id); ?>"><?php _e('默认折叠目录', 'kratos'); ?></label>
        </p>
<?php
    }

    public function update($new_instance, $old_instance)
    {
        $instance = (array) $old_instance;
        $instance['collapsed'] = !empty($new_instance['collapsed']) ? 1 : 0;
        return $instance;
    }
}

/**
 * 链接（友情链接）小工具
 *
 * 接管 WordPress 原生「链接」小工具（WP_Widget_Links，id_base=links，
 * option_name=widget_links），沿用原生实例数据（category / orderby / limit /
 * description），因此已配置的链接小工具无需重设即可套用新样式。
 *
 * 图标展示逻辑与友链页面（[friend_links] 短码）保持一致：
 *   - 有 Logo → 展示图片，加载失败时 onerror 回退到首字母占位
 *   - 无 Logo → 直接展示首字母占位（渐变底色由站点名 hash 稳定生成）
 * 复用 inc/theme-friend-links.php 里的 kratos_friend_first_letter /
 * kratos_friend_placeholder_color（渲染时两文件均已加载）。
 */
class widget_links extends WP_Widget
{
    public function __construct()
    {
        $widget_ops = array(
            'name'                        => __('Kratos-plus - 链接', 'kratos'),
            'description'                 => __('展示友情链接，图标复用友链页面的 Logo / 首字母占位逻辑', 'kratos'),
            'classname'                   => 'widget_links',
            'customize_selective_refresh' => true,
        );
        parent::__construct('links', __('Kratos-plus - 链接', 'kratos'), $widget_ops);
    }

    public function widget($args, $instance)
    {
        if (!function_exists('get_bookmarks')) {
            return;
        }

        $title    = isset($instance['title']) ? $instance['title'] : '';
        $category = isset($instance['category']) ? (int) $instance['category'] : 0;
        $title    = apply_filters('widget_title', $title, $instance, $this->id_base);
        if ($title === '' || $title === null) {
            if ($category > 0) {
                $term = get_term($category, 'link_category');
                if ($term && !is_wp_error($term)) {
                    $title = $term->name;
                }
            }
            if ($title === '' || $title === null) {
                $title = __('友情链接', 'kratos');
            }
        }
        $orderby  = isset($instance['orderby']) ? $instance['orderby'] : 'name';
        if (!in_array($orderby, array('name', 'rating', 'id', 'url', 'rand'), true)) {
            $orderby = 'name';
        }
        $limit     = (isset($instance['limit']) && $instance['limit'] !== '' && (int) $instance['limit'] > 0) ? (int) $instance['limit'] : -1;
        $show_desc  = !empty($instance['description']);
        $show_image = !isset($instance['show_image']) || !empty($instance['show_image']);
        $order      = ($orderby === 'rating') ? 'DESC' : 'ASC';

        $bookmarks = get_bookmarks(array(
            'category'       => $category ? $category : '',
            'orderby'        => $orderby,
            'order'          => $order,
            'limit'          => $limit,
            'hide_invisible' => 1,
        ));

        echo $args['before_widget'];
        if ($title !== '') {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }

        if (empty($bookmarks)) {
            echo '<div class="w-links-empty">' . esc_html__('暂无链接', 'kratos') . '</div>';
            echo $args['after_widget'];
            return;
        }

        echo '<ul class="wfl-list">';
        foreach ($bookmarks as $bm) {
            $name   = $bm->link_name !== '' ? $bm->link_name : __('（未命名）', 'kratos');
            $url    = $bm->link_url;
            $desc   = $bm->link_description;
            $img    = $bm->link_image;
            $target = $bm->link_target ? $bm->link_target : '_blank';
            $seed   = $name !== '' ? $name : $url;
            $letter = function_exists('kratos_friend_first_letter') ? kratos_friend_first_letter($seed) : mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
            $bg     = function_exists('kratos_friend_placeholder_color') ? kratos_friend_placeholder_color($seed) : '#336699';
            $tip    = $name . ($desc !== '' ? ' — ' . $desc : '');

            echo '<li class="wfl-li">';
            echo '<a class="wfl-item" href="' . esc_url($url) . '" target="' . esc_attr($target) . '" rel="nofollow noopener external" title="' . esc_attr($tip) . '">';
            if ($show_image) {
                echo '<span class="wfl-logo">';
                if ($img !== '') {
                    echo '<img src="' . esc_url($img) . '" alt="' . esc_attr($name) . '" loading="lazy" onerror="this.parentNode.classList.add(\'is-fallback\');this.remove();" />';
                    echo '<span class="wfl-logo-letter wfl-logo-fallback" style="background:' . esc_attr($bg) . ';">' . esc_html($letter) . '</span>';
                } else {
                    echo '<span class="wfl-logo-letter" style="background:' . esc_attr($bg) . ';">' . esc_html($letter) . '</span>';
                }
                echo '</span>';
            }
            echo '<span class="wfl-meta"><span class="wfl-name">' . esc_html($name) . '</span>';
            if ($show_desc && $desc !== '') {
                echo '<span class="wfl-desc">' . esc_html($desc) . '</span>';
            }
            echo '</span>';
            echo '</a>';
            echo '</li>';
        }
        echo '</ul>';

        echo $args['after_widget'];
    }

    public function update($new_instance, $old_instance)
    {
        $instance             = array();
        $instance['title']    = isset($new_instance['title']) ? sanitize_text_field($new_instance['title']) : '';
        $instance['category'] = isset($new_instance['category']) ? (int) $new_instance['category'] : 0;
        $orderby              = isset($new_instance['orderby']) ? $new_instance['orderby'] : 'name';
        $instance['orderby']  = in_array($orderby, array('name', 'rating', 'id', 'url', 'rand'), true) ? $orderby : 'name';
        $instance['limit']    = (isset($new_instance['limit']) && (int) $new_instance['limit'] > 0) ? (int) $new_instance['limit'] : -1;
        $instance['description'] = !empty($new_instance['description']) ? 1 : 0;
        $instance['show_image'] = !empty($new_instance['show_image']) ? 1 : 0;
        return $instance;
    }

    public function form($instance)
    {
        $instance = wp_parse_args((array) $instance, array(
            'title'       => '',
            'category'    => 0,
            'orderby'     => 'name',
            'limit'       => -1,
            'description' => 0,
            'show_image'  => 1,
        ));
        $link_cats = get_terms(array('taxonomy' => 'link_category', 'hide_empty' => false));
        $limit     = ((int) $instance['limit'] > 0) ? (int) $instance['limit'] : '';
    ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('栏目标题：', 'kratos'); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($instance['title']); ?>" />
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('category'); ?>"><?php _e('链接分类：', 'kratos'); ?></label>
            <select class="widefat" id="<?php echo $this->get_field_id('category'); ?>" name="<?php echo $this->get_field_name('category'); ?>">
                <option value="0" <?php selected(0, (int) $instance['category']); ?>><?php _e('全部分类', 'kratos'); ?></option>
                <?php if (!is_wp_error($link_cats)) foreach ($link_cats as $cat) { ?>
                    <option value="<?php echo (int) $cat->term_id; ?>" <?php selected((int) $cat->term_id, (int) $instance['category']); ?>><?php echo esc_html($cat->name); ?></option>
                <?php } ?>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('orderby'); ?>"><?php _e('排序方式：', 'kratos'); ?></label>
            <select class="widefat" id="<?php echo $this->get_field_id('orderby'); ?>" name="<?php echo $this->get_field_name('orderby'); ?>">
                <option value="name" <?php selected('name', $instance['orderby']); ?>><?php _e('名称', 'kratos'); ?></option>
                <option value="rating" <?php selected('rating', $instance['orderby']); ?>><?php _e('评级', 'kratos'); ?></option>
                <option value="id" <?php selected('id', $instance['orderby']); ?>><?php _e('添加顺序', 'kratos'); ?></option>
                <option value="url" <?php selected('url', $instance['orderby']); ?>><?php _e('地址', 'kratos'); ?></option>
                <option value="rand" <?php selected('rand', $instance['orderby']); ?>><?php _e('随机', 'kratos'); ?></option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('limit'); ?>"><?php _e('显示数量（留空为全部）：', 'kratos'); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('limit'); ?>" name="<?php echo $this->get_field_name('limit'); ?>" type="number" min="1" step="1" value="<?php echo esc_attr($limit); ?>" />
        </p>
        <p>
            <input class="checkbox" type="checkbox" <?php checked(!empty($instance['description'])); ?> id="<?php echo $this->get_field_id('description'); ?>" name="<?php echo $this->get_field_name('description'); ?>" value="1" />
            <label for="<?php echo $this->get_field_id('description'); ?>"><?php _e('显示链接描述', 'kratos'); ?></label>
        </p>
        <p>
            <input class="checkbox" type="checkbox" <?php checked(!empty($instance['show_image'])); ?> id="<?php echo $this->get_field_id('show_image'); ?>" name="<?php echo $this->get_field_name('show_image'); ?>" value="1" />
            <label for="<?php echo $this->get_field_id('show_image'); ?>"><?php _e('显示图标', 'kratos'); ?></label>
        </p>
    <?php
    }
}

function register_widgets()
{
    register_widget('widget_ad');
    register_widget('widget_about');
    register_widget('widget_tags');
    register_widget('widget_search');
    register_widget('widget_posts');
    register_widget('widget_comments');
    register_widget('widget_toc');
    register_widget('widget_links');
}
add_action('widgets_init', 'register_widgets');
