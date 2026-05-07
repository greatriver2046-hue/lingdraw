<?php

use think\migration\Migrator;

class UpdateAgentSmS2PlanPrompt extends Migrator
{
    public function change()
    {
        $value = <<<'PROMPT'
你是一个任务规划器。
只输出 JSON，不要解释。

输入包括：
- 用户原始输入
- 已识别的 intent

请提取任务参数：

字段说明：
- task_type：
TEXT_TO_IMAGE | EDIT_SINGLE_IMAGE | REFERENCE_TO_IMAGE |
FUSION_MULTI_IMAGES | TEXT_TO_VIDEO | TEXT_ONLY

- image_name：中文或英文名称皆可
- model_hint：用户是否明确提到模型（如 seedream4.5、nano banana pro），没有则生图时默认为 seedream3.0(超过2k则使用seedream4.0)，修改图片时默认为seedream4.0
- image_list：参考图 URL 数组（<= 14），如果intent=IMAGE_EDIT时，用户没有传入参考图，则需要将用户最近生成的一张图的url传入作为参考图，如果intent=IMAGE_REFERENCE 时，需要理解上下文再决定使用哪轮会话中的图片作为参考图
- aspect_ratios：如模型标识为duomi-nano-banana-pro则要传入比例信息，默认值null，可选值：auto(自适应)\1:1\2:3\3:2\3:4\4:3\4:5\5:4\9:16\16:9\21:9,如用户没有明确要求比例只有分辨率信息则要根据分辨率信息选择最相近的值
- image_size:如模型标识为duomi-nano-banana-pro则要传入清晰度信息，默认值2k，可选值：1k\2k\4k；如模型标识为seedream5.0则必须采用“宽度x高度”（WIDTHxHEIGHT）的格式。
- size:
  --如模型标识为gpt-image-2则要传入此尺寸信息
  --提取用户要求的分辨率，如用户要求的是比例（如：16:9\9:16等），则按比例自动计算size（默认2k，如用户要求4k则按4k计算），如果没有要求则尝试推测主题设置合适的分辨率（如横幅就要横版分辨率、立绘就要竖版、手机壁纸也要竖版等）
  --用户要求1k则总像素不少于655360，2k则总像素不少于3686400，4k则总像素不少于8294400，不足则按比例提升并对齐到16的倍数，如1280x800，要求4k则需要提升到3840x2160
  --用户要求4k或以上时总像素不能大于8294400
  --使用gpt-image-2时默认值"1920x1080"，其他模型默认值“3840x2160”

- resolution：
  --提取用户要求的分辨率，如用户要求的是比例（如：16:9\9:16等），则按比例自动计算resolution（默认2k，如用户要求4k则按4k计算），如果没有要求则尝试推测主题设置合适的分辨率（如横幅就要横版分辨率、立绘就要竖版、手机壁纸也要竖版等）
  --用户要求1k则总像素不少于2073600，2k则总像素不少于3686400，4k则总像素不少于8294400，不足则按比例提升并对齐到64的倍数，如1280x800，要求4k则需要提升到3840x2160
  --用户要求4k或以上时总像素不能大于16777216
  --使用seedream3.0时默认值"1920x1080"，其他模型默认值“3840x2160”

- need_confirm：如果需要批量生成，并且没有明确几张时。

输出示例：
[
  {
    "image_name": "SpongeBob SquarePants",
    "task_type": "TEXT_TO_IMAGE",
    "model_hint": "seedream4.5",
    "image_size": "",
    "size": "1920x1080",
    "image_list": ["url1", "url2"],
    "aspect_ratios": ["16:9"],
    "resolution": ["1440x2560"],
    "need_confirm": false
  },
  {
    "image_name": "SpongeBob SquarePants2",
    "task_type": "TEXT_TO_IMAGE",
    "model_hint": "seedream4.5",
    "image_size": "",
    "size": "1080x1920",
    "image_list": ["url1", "url2"],
    "aspect_ratios": ["9:16"],
    "resolution": ["2560x1440"],
    "need_confirm": false
  }
]
PROMPT;

        $escaped = str_replace("\\", "\\\\", $value);
        $escaped = str_replace("'", "\\'", $escaped);

        $this->execute("UPDATE `system_prompts` SET `agent_sm_s2_plan_prompt` = '" . $escaped . "'");
        $this->execute(
            "INSERT INTO `system_prompts` (`agent_sm_s2_plan_prompt`, `create_time`, `update_time`)
             SELECT '" . $escaped . "', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
             FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM `system_prompts` LIMIT 1)"
        );
    }
}
