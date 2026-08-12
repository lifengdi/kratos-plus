<?php

/**
 * 主题页脚
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos+ fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 * @version 2022.05.27
 */
?>
<div class="k-footer">
    <div class="f-toolbox">
        <?php // flex column-reverse 堆叠：源码顺序自底向上（搜索在最底，回到顶部在最上） ?>
        <div class="search">
            <span class="kicon i-find"></span>
            <form class="search-form" role="search" method="get" action="<?php echo home_url('/'); ?>">
                <input type="text" name="s" id="search-footer" placeholder="<?php _e('搜点什么呢?', 'kratos'); ?>" style="display:none" />
            </form>
        </div>
        <?php // 总开关 + 页脚按钮开关都打开时才渲染（命令面板入口另有开关，见 inc/theme-command-palette.php） ?>
        <?php if (kratos_option('g_stumble', true) && kratos_option('g_stumble_button', true)) { ?>
            <div class="stumble">
                <a href="<?php echo kratos_stumble_url(); ?>" rel="nofollow" aria-label="<?php esc_attr_e('随机漫步 · 随机跳到一篇老文章', 'kratos'); ?>" title="<?php esc_attr_e('随机漫步 · 随机跳到一篇老文章', 'kratos'); ?>">
                    <span class="kicon i-tabrandom"></span>
                </a>
            </div>
        <?php } ?>
        <?php if (!empty(kratos_option('g_wechat_fieldset')['g_wechat'])) { ?>
            <div class="wechat">
                <span class="kicon i-wechat"></span>
                <div class="wechat-pic">
                    <img src="<?php echo kratos_option('g_wechat_fieldset')['g_wechat_img']; ?>">
                </div>
            </div>
        <?php } ?>
        <?php if (kratos_option('g_darkmode', false) && kratos_option('g_darkmode_toggle', true)) { ?>
            <div class="darkmode" role="button" tabindex="0" aria-pressed="false" aria-label="<?php esc_attr_e('切换为暗色模式', 'kratos'); ?>" title="<?php esc_attr_e('切换为暗色模式', 'kratos'); ?>">
                <span class="darkmode-ico" aria-hidden="true"></span>
            </div>
        <?php } ?>
        <?php if (kratos_weekday_switcher_enabled()) { ?>
            <div class="skin-switcher" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e('切换皮肤', 'kratos'); ?>" title="<?php esc_attr_e('切换皮肤', 'kratos'); ?>">
                <span class="skin-switcher-ico" aria-hidden="true"></span>
            </div>
        <?php } ?>
        <div class="gotop">
            <div class="gotop-btn">
                <span class="kicon i-up"></span>
            </div>
        </div>
    </div>
    <div class="container">
        <div class="row">
            <div class="col-12 text-center">
                <p class="social">
                    <?php
                    if (!empty(kratos_option('s_social_fieldset'))) {
                        foreach (kratos_option('s_social_fieldset') as $key => $value) {
                            if (kratos_option('s_social_fieldset')[$key]) {
                                echo '<a target="_blank" rel="nofollow" href="' . kratos_option('s_social_fieldset')[$key] . '"><i class="kicon i-' . str_replace(array("s_", "_url"), array('', ''), $key) . '"></i></a>';
                            }
                        }
                    }
                    $s_social_custom = kratos_option('s_social_custom', array());
                    if (!empty($s_social_custom) && is_array($s_social_custom)) {
                        foreach ($s_social_custom as $item) {
                            $url = isset($item['url']) ? trim((string) $item['url']) : '';
                            if ($url === '') continue;
                            $title = isset($item['title']) ? (string) $item['title'] : '';
                            $type  = isset($item['icon_type']) ? $item['icon_type'] : 'fontawesome';
                            $inner = '';
                            if ($type === 'image') {
                                $img = isset($item['icon_image']) ? trim((string) $item['icon_image']) : '';
                                if ($img === '') continue;
                                $inner = '<img src="' . esc_url($img) . '" alt="' . esc_attr($title) . '" class="social-custom-img">';
                            } else {
                                $cls = isset($item['icon']) ? trim((string) $item['icon']) : '';
                                if ($cls === '') continue;
                                $inner = '<i class="' . esc_attr($cls) . '"></i>';
                            }
                            echo '<a target="_blank" rel="nofollow" href="' . esc_url($url) . '"'
                                . ($title !== '' ? ' title="' . esc_attr($title) . '" aria-label="' . esc_attr($title) . '"' : '')
                                . '>' . $inner . '</a>';
                        }
                    }
                    ?>
                </p>
                <?php
                echo '<p>' . kratos_option('s_copyright', 'COPYRIGHT © ' . wp_date('Y') . ' ' . get_bloginfo('name') . '. ALL RIGHTS RESERVED.') . '</p>';
                echo '<p>Theme <a href="https://github.com/lifengdi/kratos-plus" target="_blank" rel="nofollow">Kratos+</a> By <a href="https://www.lifengdi.com" target="_blank" rel="nofollow">Dylan Li</a></p>';
                if (kratos_option('s_icp')) {
                    echo '<p><a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow">' . kratos_option('s_icp') . '</a></p>';
                }
                if (kratos_option('s_gov')) {
                    echo '<p><a href="' . kratos_option('s_gov_link') . '" target="_blank" rel="nofollow" ><i class="police-ico"></i>' . kratos_option('s_gov') . '</a></p>';
                }
                ?>
            </div>
        </div>
    </div>
</div>
<?php wp_footer(); ?>
</body>

</html>