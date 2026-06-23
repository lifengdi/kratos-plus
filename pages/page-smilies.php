<?php

/**
 * 表情图标
 * @author Seaton Jiang <hi@seatonjiang.com>
 * @author Dylan Li (Kratos+ fork) <https://www.lifengdi.com>
 * @license GPL-3.0 License
 */
$kratos_groups = kratos_get_smilies_groups();
$kratos_group_keys = array_keys($kratos_groups);

$kratos_tabs = '';
$kratos_panels = '';
foreach ($kratos_groups as $slug => $group) {
    $is_active = ($slug === reset($kratos_group_keys));
    $kratos_tabs .= '<a href="javascript:;" class="smile-tab' . ($is_active ? ' active' : '') . '" data-target="' . esc_attr($slug) . '">' . esc_html($group['label']) . '</a>';

    $items = '';
    foreach ($group['smilies'] as $s) {
        $items .= '<a href="javascript:grin(\'' . esc_js($s['shortcode']) . '\')"><img class="d-block" src="'
            . esc_url(apply_filters('smilies_src', ASSET_PATH . '/assets/img/smilies/' . $s['file'], $s['file'], site_url()))
            . '" alt="' . esc_attr($s['alt']) . '"></a>';
    }
    $kratos_panels .= '<div class="smile-panel' . ($is_active ? ' active' : '') . '" data-group="' . esc_attr($slug) . '">' . $items . '</div>';
}

$smilies = '';
if (count($kratos_groups) > 1) {
    $smilies .= '<div class="smile-tabs">' . $kratos_tabs . '</div>';
}
$smilies .= '<div class="smile-panels">' . $kratos_panels . '</div>';
