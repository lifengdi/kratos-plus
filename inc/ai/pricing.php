<?php
/**
 * 内置参考单价（USD / 1K tokens）—— 仅作为兜底基线。
 * 优先级：本文件 < 主题选项「AI 工具箱 → 通用设置 → 成本核算」里站长填的单价 < filter kratos_ai_pricing。
 * 这里的数字是保守估计，不作为对外承诺；真实账单请在主题选项里按自己的计费标准填。
 */
if (!defined('ABSPATH')) exit;

return array(
    'version' => '2025-01',
    'default' => array('input' => 0.001, 'output' => 0.003),
    'models' => array(
        'gpt-4o-mini'     => array('input' => 0.00015, 'output' => 0.0006),
        'gpt-4o'          => array('input' => 0.0025,  'output' => 0.01),
        'deepseek-chat'   => array('input' => 0.00014, 'output' => 0.00028),
        'moonshot-v1-8k'  => array('input' => 0.0017,  'output' => 0.0017),
        'glm-4-flash'     => array('input' => 0.0,     'output' => 0.0),
    ),
);
