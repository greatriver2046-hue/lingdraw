<?php
namespace app\admin\controller;

use app\BaseController;
use app\admin\model\SystemPrompt as SystemPromptModel;
use think\facade\Db;

class SystemPrompt extends BaseController
{
    protected function normalizeToggle($value): int
    {
        if (is_bool($value)) return $value ? 1 : 0;
        if (is_int($value)) return $value ? 1 : 0;
        if (is_float($value)) return ((int)$value) ? 1 : 0;
        $s = is_string($value) ? trim($value) : trim((string)$value);
        if ($s === '') return 0;
        $lower = strtolower($s);
        if ($lower === '1' || $lower === 'true' || $lower === 'on' || $lower === 'yes') return 1;
        return 0;
    }

    protected function ensureColumns(array $fields): void
    {
        if (empty($fields)) return;
        $cols = Db::query('SHOW COLUMNS FROM `system_prompts`');
        $existing = [];
        if (is_array($cols)) {
            foreach ($cols as $c) {
                if (isset($c['Field'])) $existing[$c['Field']] = true;
            }
        }
        foreach ($fields as $field) {
            if (!isset($existing[$field])) {
                $safe = str_replace('`', '', $field);
                Db::execute("ALTER TABLE `system_prompts` ADD COLUMN `{$safe}` TEXT NULL");
                $existing[$field] = true;
            }
        }
    }

    protected function getRequestPayload(): array
    {
        $data = $this->request->param();
        $raw = (string)$this->request->getContent();
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded)) {
            if (is_array($data) && !empty($data)) {
                return array_merge($data, $decoded);
            }
            return $decoded;
        }
        return is_array($data) ? $data : [];
    }

    public function index()
    {
        $this->ensureColumns(['pose_edit_prompt']);
        $record = SystemPromptModel::order('id', 'desc')->find();
        if (!$record) {
            return $this->success([
                'input_hint_prompt' => '',
                'image_builtin_prompt' => '',
                'image_model_param_adapt_prompt' => '',
                'llm_sidebar_system_prompt' => '',
                'llm_assistant_system_prompt' => '',
                'tool_call_callback_prompt' => '',
                'image_ocr_prompt' => '',
                'image_reverse_prompt' => '',
                'image_marker_prompt' => '',
                'pose_edit_prompt' => '',
                'style_profile_system_prompt' => '',
                'style_profile_user_prompt' => '',
                'style_profile_secondary_modification_prompt' => '',
                'agent_sm_s1_intent_prompt' => '',
                'agent_sm_s2_plan_prompt' => '',
                'agent_sm_s3_image_prompt' => '',
                'agent_sm_s3_video_prompt' => '',
                'agent_sm_s3_text_prompt' => '',
                'agent_sm_s5_result_prompt' => '',
                'article_sm_s0_intent_model_identity' => '',
                'article_sm_s0_intent_system_prompt' => '',
                'article_sm_skeleton_outline_model_identity' => '',
                'article_sm_skeleton_outline_system_prompt' => '',
                'article_sm_skeleton_outline_user_prompt' => '',
                'article_sm_need_assess_model_identity' => '',
                'article_sm_need_assess_system_prompt' => '',
                'article_sm_need_assess_user_prompt' => '',
                'article_sm_query_plan_model_identity' => '',
                'article_sm_query_plan_system_prompt' => '',
                'article_sm_query_plan_user_prompt' => '',
                'article_sm_fact_pack_model_identity' => '',
                'article_sm_fact_pack_system_prompt' => '',
                'article_sm_fact_pack_user_prompt' => '',
                'article_sm_outline_model_identity' => '',
                'article_sm_outline_system_prompt' => '',
                'article_sm_outline_user_prompt' => '',
                'article_sm_neutral_draft_model_identity' => '',
                'article_sm_neutral_draft_system_prompt' => '',
                'article_sm_neutral_draft_user_prompt' => '',
                'article_sm_neutral_revision_enabled' => 0,
                'article_sm_neutral_revision_model_identity' => '',
                'article_sm_neutral_revision_system_prompt' => '',
                'article_sm_neutral_revision_user_prompt' => '',
                'article_sm_style_transfer_model_identity' => '',
                'article_sm_style_transfer_system_prompt' => '',
                'article_sm_style_transfer_user_prompt' => '',
                'article_sm_style_qa_model_identity' => '',
                'article_sm_style_qa_system_prompt' => '',
                'article_sm_style_qa_user_prompt' => '',
                'article_sm_style_rewrite_model_identity' => '',
                'article_sm_style_rewrite_system_prompt' => '',
                'article_sm_style_rewrite_user_prompt' => '',
                'article_sm_risk_rewrite_model_identity' => '',
                'article_sm_risk_rewrite_system_prompt' => '',
                'article_sm_risk_rewrite_user_prompt' => ''
            ]);
        }
        return $this->success([
            'input_hint_prompt' => $record->input_hint_prompt ?? '',
            'image_builtin_prompt' => $record->image_builtin_prompt ?? '',
            'image_model_param_adapt_prompt' => $record->image_model_param_adapt_prompt ?? '',
            'llm_sidebar_system_prompt' => $record->llm_sidebar_system_prompt ?? '',
            'llm_assistant_system_prompt' => $record->llm_assistant_system_prompt ?? '',
            'tool_call_callback_prompt' => $record->tool_call_callback_prompt ?? '',
            'image_ocr_prompt' => $record->image_ocr_prompt ?? '',
            'image_reverse_prompt' => $record->image_reverse_prompt ?? '',
            'image_marker_prompt' => $record->image_marker_prompt ?? '',
            'pose_edit_prompt' => $record->pose_edit_prompt ?? '',
            'erase_tool_prompt' => $record->erase_tool_prompt ?? '',
            'style_profile_system_prompt' => $record->style_profile_system_prompt ?? '',
            'style_profile_user_prompt' => $record->style_profile_user_prompt ?? '',
            'style_profile_secondary_modification_prompt' => $record->style_profile_secondary_modification_prompt ?? '',
            'agent_sm_s1_intent_prompt' => $record->agent_sm_s1_intent_prompt ?? '',
            'agent_sm_s2_plan_prompt' => $record->agent_sm_s2_plan_prompt ?? '',
            'agent_sm_s3_image_prompt' => $record->agent_sm_s3_image_prompt ?? '',
            'agent_sm_s3_video_prompt' => $record->agent_sm_s3_video_prompt ?? '',
            'agent_sm_s3_text_prompt' => $record->agent_sm_s3_text_prompt ?? '',
            'agent_sm_s5_result_prompt' => $record->agent_sm_s5_result_prompt ?? '',
            'article_sm_s0_intent_model_identity' => $record->article_sm_s0_intent_model_identity ?? '',
            'article_sm_s0_intent_system_prompt' => $record->article_sm_s0_intent_system_prompt ?? '',
            'article_sm_skeleton_outline_model_identity' => $record->article_sm_skeleton_outline_model_identity ?? '',
            'article_sm_skeleton_outline_system_prompt' => $record->article_sm_skeleton_outline_system_prompt ?? '',
            'article_sm_skeleton_outline_user_prompt' => $record->article_sm_skeleton_outline_user_prompt ?? '',
            'article_sm_need_assess_model_identity' => $record->article_sm_need_assess_model_identity ?? '',
            'article_sm_need_assess_system_prompt' => $record->article_sm_need_assess_system_prompt ?? '',
            'article_sm_need_assess_user_prompt' => $record->article_sm_need_assess_user_prompt ?? '',
            'article_sm_query_plan_model_identity' => $record->article_sm_query_plan_model_identity ?? '',
            'article_sm_query_plan_system_prompt' => $record->article_sm_query_plan_system_prompt ?? '',
            'article_sm_query_plan_user_prompt' => $record->article_sm_query_plan_user_prompt ?? '',
            'article_sm_fact_pack_model_identity' => $record->article_sm_fact_pack_model_identity ?? '',
            'article_sm_fact_pack_system_prompt' => $record->article_sm_fact_pack_system_prompt ?? '',
            'article_sm_fact_pack_user_prompt' => $record->article_sm_fact_pack_user_prompt ?? '',
            'article_sm_outline_model_identity' => $record->article_sm_outline_model_identity ?? '',
            'article_sm_outline_system_prompt' => $record->article_sm_outline_system_prompt ?? '',
            'article_sm_outline_user_prompt' => $record->article_sm_outline_user_prompt ?? '',
            'article_sm_neutral_draft_model_identity' => $record->article_sm_neutral_draft_model_identity ?? '',
            'article_sm_neutral_draft_system_prompt' => $record->article_sm_neutral_draft_system_prompt ?? '',
            'article_sm_neutral_draft_user_prompt' => $record->article_sm_neutral_draft_user_prompt ?? '',
            'article_sm_neutral_revision_enabled' => $this->normalizeToggle($record->article_sm_neutral_revision_enabled ?? 0),
            'article_sm_neutral_revision_model_identity' => $record->article_sm_neutral_revision_model_identity ?? '',
            'article_sm_neutral_revision_system_prompt' => $record->article_sm_neutral_revision_system_prompt ?? '',
            'article_sm_neutral_revision_user_prompt' => $record->article_sm_neutral_revision_user_prompt ?? '',
            'article_sm_style_transfer_model_identity' => $record->article_sm_style_transfer_model_identity ?? '',
            'article_sm_style_transfer_system_prompt' => $record->article_sm_style_transfer_system_prompt ?? '',
            'article_sm_style_transfer_user_prompt' => $record->article_sm_style_transfer_user_prompt ?? '',
            'article_sm_style_qa_model_identity' => $record->article_sm_style_qa_model_identity ?? '',
            'article_sm_style_qa_system_prompt' => $record->article_sm_style_qa_system_prompt ?? '',
            'article_sm_style_qa_user_prompt' => $record->article_sm_style_qa_user_prompt ?? '',
            'article_sm_style_rewrite_model_identity' => $record->article_sm_style_rewrite_model_identity ?? '',
            'article_sm_style_rewrite_system_prompt' => $record->article_sm_style_rewrite_system_prompt ?? '',
            'article_sm_style_rewrite_user_prompt' => $record->article_sm_style_rewrite_user_prompt ?? '',
            'article_sm_risk_rewrite_model_identity' => $record->article_sm_risk_rewrite_model_identity ?? '',
            'article_sm_risk_rewrite_system_prompt' => $record->article_sm_risk_rewrite_system_prompt ?? '',
            'article_sm_risk_rewrite_user_prompt' => $record->article_sm_risk_rewrite_user_prompt ?? ''
        ]);
    }

    public function save()
    {
        $data = $this->getRequestPayload();
        try {
            Db::execute('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
            $allowed = [
                'input_hint_prompt',
                'image_builtin_prompt',
                'image_model_param_adapt_prompt',
                'llm_sidebar_system_prompt',
                'llm_assistant_system_prompt',
                'tool_call_callback_prompt',
                'image_ocr_prompt',
                'image_reverse_prompt',
                'image_marker_prompt',
                'pose_edit_prompt',
                'erase_tool_prompt',
                'style_profile_system_prompt',
                'style_profile_user_prompt',
                'style_profile_secondary_modification_prompt',
                'agent_sm_s1_intent_prompt',
                'agent_sm_s2_plan_prompt',
                'agent_sm_s3_image_prompt',
                'agent_sm_s3_video_prompt',
                'agent_sm_s3_text_prompt',
                'agent_sm_s5_result_prompt',
                'article_sm_s0_intent_model_identity',
                'article_sm_s0_intent_system_prompt',
                'article_sm_skeleton_outline_model_identity',
                'article_sm_skeleton_outline_system_prompt',
                'article_sm_skeleton_outline_user_prompt',
                'article_sm_need_assess_model_identity',
                'article_sm_need_assess_system_prompt',
                'article_sm_need_assess_user_prompt',
                'article_sm_query_plan_model_identity',
                'article_sm_query_plan_system_prompt',
                'article_sm_query_plan_user_prompt',
                'article_sm_fact_pack_model_identity',
                'article_sm_fact_pack_system_prompt',
                'article_sm_fact_pack_user_prompt',
                'article_sm_outline_model_identity',
                'article_sm_outline_system_prompt',
                'article_sm_outline_user_prompt',
                'article_sm_neutral_draft_model_identity',
                'article_sm_neutral_draft_system_prompt',
                'article_sm_neutral_draft_user_prompt',
                'article_sm_neutral_revision_enabled',
                'article_sm_neutral_revision_model_identity',
                'article_sm_neutral_revision_system_prompt',
                'article_sm_neutral_revision_user_prompt',
                'article_sm_style_transfer_model_identity',
                'article_sm_style_transfer_system_prompt',
                'article_sm_style_transfer_user_prompt',
                'article_sm_style_qa_model_identity',
                'article_sm_style_qa_system_prompt',
                'article_sm_style_qa_user_prompt',
                'article_sm_style_rewrite_model_identity',
                'article_sm_style_rewrite_system_prompt',
                'article_sm_style_rewrite_user_prompt',
                'article_sm_risk_rewrite_model_identity',
                'article_sm_risk_rewrite_system_prompt',
                'article_sm_risk_rewrite_user_prompt',
            ];

            $payload = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = is_string($data[$field]) ? $data[$field] : (string)$data[$field];
                }
            }

            if (empty($payload)) {
                return $this->error('无可保存字段', 400);
            }

            $this->ensureColumns(array_keys($payload));
            $record = SystemPromptModel::order('id', 'desc')->find();
            if ($record) {
                $record->save($payload);
            } else {
                $record = SystemPromptModel::create(array_merge(array_fill_keys($allowed, ''), $payload));
            }
            return $this->success($payload, '保存成功');
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Unknown column') !== false && (isset($data['style_profile_system_prompt']) || isset($data['style_profile_user_prompt']) || isset($data['style_profile_secondary_modification_prompt']))) {
                $msg = '数据库缺少作者风格分析提示词字段，请先执行迁移：20260115120000_add_style_profile_prompts_to_system_prompts、20260117120000_add_style_profile_secondary_modification_prompt_to_system_prompts';
            }
            if (strpos($msg, 'Unknown column') !== false && (isset($data['article_sm_s0_intent_system_prompt']) || isset($data['article_sm_skeleton_outline_system_prompt']) || isset($data['article_sm_skeleton_outline_user_prompt']) || isset($data['article_sm_need_assess_system_prompt']) || isset($data['article_sm_need_assess_user_prompt']) || isset($data['article_sm_query_plan_system_prompt']) || isset($data['article_sm_query_plan_user_prompt']) || isset($data['article_sm_fact_pack_system_prompt']) || isset($data['article_sm_fact_pack_user_prompt']) || isset($data['article_sm_outline_system_prompt']) || isset($data['article_sm_outline_user_prompt']) || isset($data['article_sm_neutral_draft_system_prompt']) || isset($data['article_sm_neutral_draft_user_prompt']) || isset($data['article_sm_neutral_revision_system_prompt']) || isset($data['article_sm_neutral_revision_user_prompt']) || isset($data['article_sm_style_transfer_system_prompt']) || isset($data['article_sm_style_transfer_user_prompt']) || isset($data['article_sm_style_qa_system_prompt']) || isset($data['article_sm_style_qa_user_prompt']) || isset($data['article_sm_style_rewrite_system_prompt']) || isset($data['article_sm_style_rewrite_user_prompt']) || isset($data['article_sm_risk_rewrite_system_prompt']) || isset($data['article_sm_risk_rewrite_user_prompt']))) {
                $msg = '数据库缺少文章创作状态机提示词字段，请先执行迁移：20260116120000_add_article_creation_state_machine_prompts_to_system_prompts、20260119120000_add_article_creation_state_machine_s0_prompt_to_system_prompts';
            }
            return $this->error('保存失败: ' . $msg);
        }
    }
}
