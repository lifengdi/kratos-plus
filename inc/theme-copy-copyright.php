<?php

/**
 * 复制内容版权提示
 * 用户复制文章正文时，自动在剪贴板末尾追加来源链接和版权声明。
 *
 * @author Dylan Li (Kratos-plus) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */

defined('ABSPATH') || exit;

define('KRATOS_COPY_COPYRIGHT_TEXT_DEFAULT', "\n\n——\n作者：%author%\n链接：%url%\n来源：%site%\n著作权归作者所有。商业转载请联系作者获得授权，非商业转载请注明出处。");

function kratos_copy_copyright_script()
{
    if (!kratos_option('g_copy_copyright', false)) {
        return;
    }
    if (!is_singular('post')) {
        return;
    }

    // wp_enqueue_scripts 阶段还没进 loop，get_the_author() 会是空，从 queried object 取
    $post = get_queried_object();
    $min  = max(0, intval(kratos_option('g_copy_copyright_min', 30)));
    $tpl  = kratos_option('g_copy_copyright_text', KRATOS_COPY_COPYRIGHT_TEXT_DEFAULT);
    $text = str_replace(
        array('%author%', '%url%', '%site%'),
        array(get_the_author_meta('display_name', $post->post_author), get_permalink($post), get_bloginfo('name')),
        $tpl
    );

    $js = 'document.addEventListener("copy",function(e){' .
        'var s=window.getSelection();if(!s||s.toString().length<' . $min . ')return;' .
        'var t=s.toString()+' . wp_json_encode($text) . ';' .
        'e.clipboardData.setData("text/plain",t);e.preventDefault();' .
        '});';

    wp_add_inline_script('kratos', $js);
}
add_action('wp_enqueue_scripts', 'kratos_copy_copyright_script', 20);
