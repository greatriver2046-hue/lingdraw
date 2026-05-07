<?php

use think\migration\Migrator;

class AddArticleCreationStateMachinePromptsToSystemPrompts extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts');

        $columns = [
            'article_sm_need_assess_system_prompt' => '文章创作状态机 S1 资料评估 System Prompt',
            'article_sm_need_assess_user_prompt' => '文章创作状态机 S1 资料评估 User Prompt 模板',
            'article_sm_query_plan_system_prompt' => '文章创作状态机 S2 检索计划 System Prompt',
            'article_sm_query_plan_user_prompt' => '文章创作状态机 S2 检索计划 User Prompt 模板',
            'article_sm_fact_pack_system_prompt' => '文章创作状态机 S3 事实包提炼 System Prompt',
            'article_sm_fact_pack_user_prompt' => '文章创作状态机 S3 事实包提炼 User Prompt 模板',
            'article_sm_outline_system_prompt' => '文章创作状态机 S4 大纲生成 System Prompt',
            'article_sm_outline_user_prompt' => '文章创作状态机 S4 大纲生成 User Prompt 模板',
            'article_sm_neutral_draft_system_prompt' => '文章创作状态机 S5 中性稿生成 System Prompt',
            'article_sm_neutral_draft_user_prompt' => '文章创作状态机 S5 中性稿生成 User Prompt 模板',
            'article_sm_style_transfer_system_prompt' => '文章创作状态机 S6 风格迁移改写 System Prompt',
            'article_sm_style_transfer_user_prompt' => '文章创作状态机 S6 风格迁移改写 User Prompt 模板',
            'article_sm_style_qa_system_prompt' => '文章创作状态机 S7 风格评审 System Prompt',
            'article_sm_style_qa_user_prompt' => '文章创作状态机 S7 风格评审 User Prompt 模板',
            'article_sm_style_rewrite_system_prompt' => '文章创作状态机 S7b 风格问题局部重写 System Prompt',
            'article_sm_style_rewrite_user_prompt' => '文章创作状态机 S7b 风格问题局部重写 User Prompt 模板',
            'article_sm_risk_rewrite_system_prompt' => '文章创作状态机 S9 风险段落重写 System Prompt',
            'article_sm_risk_rewrite_user_prompt' => '文章创作状态机 S9 风险段落重写 User Prompt 模板',
        ];

        foreach ($columns as $name => $comment) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, 'text', ['null' => true, 'comment' => $comment]);
            }
        }

        $table->save();

        $escapeSql = function (string $s): string {
            $s = str_replace("\\", "\\\\", $s);
            return str_replace("'", "\\'", $s);
        };

        $defaults = [
            'article_sm_need_assess_system_prompt' => "你是写作前的资料评估器。你必须只输出严格 JSON，不得输出任何其他文本。",
            'article_sm_need_assess_user_prompt' => "请判断生成这篇文章是否需要联网补充资料。\n\n输入：\n- 主题/要点：{{TOPIC}}\n- 体裁：{{GENRE}}\n- 字数：{{WORD_COUNT}}\n- 时效：若涉及“最新/2026/最近/对比/排行榜/政策/版本/价格/发布”，则需要。\n\n输出 JSON：{\n  \"research_needed\": false,\n  \"reasons\": [],\n  \"unknowns\": [],\n  \"required_evidence_types\": [],\n  \"time_range\": \"any\",\n  \"min_sources\": 5,\n  \"risk_notes\": []\n}",
            'article_sm_query_plan_system_prompt' => "你是检索计划生成器。你必须只输出严格 JSON，不得输出任何其他文本。",
            'article_sm_query_plan_user_prompt' => "主题/要点：{{TOPIC}}\n\nneed_assess：\n{{NEED_ASSESS_JSON}}\n\n请生成检索计划 JSON：{\n  \"subtopics\": [{\"id\":\"s1\",\"goal\":\"\"}],\n  \"search_queries\": [{\"subtopic_id\":\"s1\",\"query\":\"\",\"query_alt\":[],\"include\":[],\"exclude\":[],\"time_range\":\"any\"}]\n}",
            'article_sm_fact_pack_system_prompt' => "你是事实包提炼器。你必须只输出严格 JSON，不得输出任何其他文本。",
            'article_sm_fact_pack_user_prompt' => "主题/要点：{{TOPIC}}\n\n资料摘录（每条包含 source_id/title/content_excerpt；禁止输出任何 URL）：\n{{FETCHED_FOR_FACT_PACK_JSON}}\n\n请输出 fact_pack JSON：{\n  \"facts\": [{\"statement\":\"\",\"source_ids\":[1],\"date\":null,\"confidence\":0.5}],\n  \"definitions\": [],\n  \"stats\": [],\n  \"cases\": [],\n  \"quotes\": [],\n  \"conflicts\": [],\n  \"open_questions\": [],\n  \"grounding_rules\": {\n    \"allowed_assertions\": [],\n    \"must_hedge_topics\": [],\n    \"citation_policy\": {\"max_citations_per_paragraph\": 2, \"format\": \"[n]\"}\n  }\n}\n\n要求：\n- 禁止输出任何 URL（包含 http/https/域名）\n- 引用只能用 source_ids（取值来自资料摘录里的 source_id）\n- statement/term/definition 等字段内不得包含 URL",
            'article_sm_outline_system_prompt' => "你是文章大纲生成器。你可以输出 Markdown，但必须先输出一段严格 JSON（只包含 outline_json 字段），然后换行再输出 Markdown 大纲。",
            'article_sm_outline_user_prompt' => "主题/要点：{{TOPIC}}\n体裁：{{GENRE}}\n字数：{{WORD_COUNT}}\n\nstyle_runtime_config：\n{{STYLE_RUNTIME_JSON}}\n\nfact_pack：\n{{FACT_PACK_JSON}}\n\n要求：\n- 先输出 JSON：{\"outline_json\": {\"sections\": [{\"title\":\"\",\"intent\":\"\"}]}}\n- 再输出 Markdown 大纲",
            'article_sm_neutral_draft_system_prompt' => "你是写作引擎。请根据大纲与事实包生成中性稿（Markdown）。要求：\n- 优先正确性与覆盖度\n- 禁止输出任何 URL（包含 http/https/域名）\n- 引用只能使用编号 [n]（n 必须来自 citation_sources），每段最多 2 个\n- 若无可靠来源，不要引用\n- 未证实点必须弱化表达",
            'article_sm_neutral_draft_user_prompt' => "标题：{{TITLE}}\n主题/要点：{{TOPIC}}\n体裁：{{GENRE}}\n字数目标：{{WORD_COUNT}}\n\ncitation_sources：\n{{CITATION_SOURCES_JSON}}\n\n大纲：\n{{OUTLINE_TEXT}}\n\nfact_pack：\n{{FACT_PACK_JSON}}",
            'article_sm_style_transfer_system_prompt' => "你是风格迁移改写器。只能改写表达与节奏，不能改变事实、逻辑与结构。引用编号必须原样保留。必须严格遵守禁止项。输出 Markdown 正文。",
            'article_sm_style_transfer_user_prompt' => "风格指南（严格执行）：\n{{STYLE_GUIDE}}\n\n中性稿（待改写）：\n{{NEUTRAL_DRAFT}}\n\n要求：\n- 不新增未经证实的信息\n- 禁止输出任何 URL（包含 http/https/域名）\n- 每段引用上限 2 个，引用编号格式保持不变\n- 使用短句、口语化与节奏感，尽量贴近作者口吻\n- 模板用于语气与节奏，不要机械复读",
            'article_sm_style_qa_system_prompt' => "你是风格评审器。你必须只输出严格 JSON，不得输出任何其他文本。",
            'article_sm_style_qa_user_prompt' => "风格评审清单：\n{{STYLE_JUDGE_CHECKLIST_JSON}}\n\n禁止项 must_not：\n{{MUST_NOT_JSON}}\n\n正文：\n{{STYLED_DRAFT}}\n\n输出 JSON：{\n  \"dimension_scores\": [{\"name\":\"\",\"score\":0.0}],\n  \"violations\": [],\n  \"weak_segments\": [],\n  \"rewrite_tasks\": [{\"segment_hint\":\"\",\"instruction\":\"\"}],\n  \"pass\": true\n}",
            'article_sm_style_rewrite_system_prompt' => "你是局部重写器。只重写被指出的问题段落，保持事实与引用编号不变。禁止输出任何 URL。输出完整 Markdown 正文。",
            'article_sm_style_rewrite_user_prompt' => "重写任务：\n{{REWRITE_TASKS_JSON}}\n\n原正文：\n{{STYLED_DRAFT}}",
            'article_sm_risk_rewrite_system_prompt' => "你是风险段落重写器。目标：降低与作者语料的表达相似度，但保持事实与逻辑不变。引用编号必须原样保留。禁止输出任何 URL。输出完整 Markdown 正文。",
            'article_sm_risk_rewrite_user_prompt' => "风险报告：\n{{RISK_REPORT_JSON}}\n\n原正文：\n{{STYLED_DRAFT}}",
        ];

        foreach ($defaults as $field => $value) {
            $sql = "UPDATE `system_prompts` SET `{$field}` = '" . $escapeSql($value) . "' WHERE `{$field}` IS NULL OR `{$field}` = ''";
            $this->execute($sql);
        }
    }
}

