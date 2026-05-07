<template>
  <div class="system-prompts">
    <el-card class="form-card" shadow="never">
      <el-form :model="form" label-position="top" ref="formRef" class="prompts-form">
        <el-tabs v-model="activeTab" class="prompts-tabs">
          <el-tab-pane label="输入框" name="input_hint_prompt">
            <el-form-item label="文生图输入框prompt优化提示词">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于输入框的优化输入内容按钮</el-tag></div>
              <el-input v-model="form.input_hint_prompt" type="textarea" :autosize="{ minRows: minRowsInputHint, maxRows: minRowsInputHint }" placeholder="用于输入框的优化输入内容按钮" />
            </el-form-item>
          </el-tab-pane>
          <el-tab-pane label="生图" name="image_builtin_prompt">
            <el-form-item label="文生图内置提示词">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">提交到生图大模型前优化提示词</el-tag></div>
              <el-input v-model="form.image_builtin_prompt" type="textarea" :autosize="{ minRows: minRowsImageBuiltin, maxRows: minRowsImageBuiltin }" placeholder="提交到生图大模型前优化提示词" />
            </el-form-item>
            <el-form-item label="生图模型参数适配化提示词">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于将入参通过LLM适配为不同生图模型所需参数（建议严格输出JSON）</el-tag></div>
              <el-input v-model="form.image_model_param_adapt_prompt" type="textarea" :autosize="{ minRows: minRowsImageParamAdapt, maxRows: minRowsImageParamAdapt }" placeholder="用于将入参通过LLM适配为不同生图模型所需参数" />
            </el-form-item>
          </el-tab-pane>
          <el-tab-pane label="LLM" name="llm">
            <el-form-item label="生图界面右侧LLM对话框系统提示词">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于LLM对话系统提示词</el-tag></div>
              <el-input v-model="form.llm_sidebar_system_prompt" type="textarea" :autosize="{ minRows: minRowsLlmSidebar, maxRows: minRowsLlmSidebar }" placeholder="用于LLM对话系统提示词" />
            </el-form-item>
            <el-form-item label="llm助手系统提示词（任务自动化、工具自动选择）">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于任务自动化、工具自动选择</el-tag></div>
              <el-input v-model="form.llm_assistant_system_prompt" type="textarea" :autosize="{ minRows: minRowsLlmAssistant, maxRows: minRowsLlmAssistant }" placeholder="用于任务自动化、工具自动选择" />
            </el-form-item>
          </el-tab-pane>
          <el-tab-pane label="工具" name="tool">
            <el-form-item label="工具调用回调系统提示词">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于工具调用回调的系统提示词</el-tag></div>
              <el-input v-model="form.tool_call_callback_prompt" type="textarea" :autosize="{ minRows: minRowsToolCallCallback, maxRows: minRowsToolCallCallback }" placeholder="用于工具调用回调的系统提示词" />
            </el-form-item>
          </el-tab-pane>
          <el-tab-pane label="图片理解" name="image_understanding">
            <el-form-item label="图片文字提取提示词">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于提取图片中文字时的系统提示词</el-tag></div>
              <el-input v-model="form.image_ocr_prompt" type="textarea" :autosize="{ minRows: minRowsImageOcr, maxRows: minRowsImageOcr }" placeholder="用于提取图片中文字时的系统提示词" />
            </el-form-item>
            <el-form-item label="图片逆推提示词">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于逆推图片内容提示词的系统提示词</el-tag></div>
              <el-input v-model="form.image_reverse_prompt" type="textarea" :autosize="{ minRows: minRowsImageReverse, maxRows: minRowsImageReverse }" placeholder="用于逆推图片内容提示词的系统提示词" />
            </el-form-item>
            <el-form-item label="图片标记点识别提示词">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于识别图片中标记点内容的系统提示词</el-tag></div>
              <el-input v-model="form.image_marker_prompt" type="textarea" :autosize="{ minRows: minRowsImageMarker, maxRows: minRowsImageMarker }" placeholder="用于识别图片中标记点内容的系统提示词" />
            </el-form-item>
            <el-form-item label="人物动作修改提示词">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于人物动作修改的提示词</el-tag></div>
              <el-input v-model="form.pose_edit_prompt" type="textarea" :autosize="{ minRows: minRowsPoseEdit, maxRows: minRowsPoseEdit }" placeholder="用于人物动作修改的提示词" />
            </el-form-item>
            <el-form-item label="擦除工具默认提示词">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于图片擦除工具的提示词</el-tag></div>
              <el-input v-model="form.erase_tool_prompt" type="textarea" :autosize="{ minRows: minRowsEraseTool, maxRows: minRowsEraseTool }" placeholder="用于图片擦除工具的提示词" />
            </el-form-item>
          </el-tab-pane>
          <el-tab-pane label="作者风格分析" name="style_profile">
            <el-form-item label="System Prompt">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">要求严格输出 JSON，不允许 Markdown/解释/代码块</el-tag></div>
              <el-input v-model="form.style_profile_system_prompt" type="textarea" :autosize="{ minRows: minRowsStyleProfileSystem, maxRows: minRowsStyleProfileSystem }" placeholder="作者风格分析 system prompt" />
            </el-form-item>
            <el-form-item label="Style Profile 二次修改 Prompt">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于生成初版Style Profile后再二次改写为最终版（只输出JSON）</el-tag></div>
              <el-input v-model="form.style_profile_secondary_modification_prompt" type="textarea" :autosize="{ minRows: minRowsStyleProfileSecondary, maxRows: minRowsStyleProfileSecondary }" placeholder="Style Profile 二次修改 prompt（可选）" />
            </el-form-item>
          </el-tab-pane>
          <el-tab-pane label="通用创作状态机" name="agent_state_machine">
            <el-form-item label="S1 意图解析 Prompt（轻）">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">只输出 JSON：intent/has_image_url/is_batch_request</el-tag></div>
              <el-input v-model="form.agent_sm_s1_intent_prompt" type="textarea" :autosize="{ minRows: minRowsAgentS1, maxRows: minRowsAgentS1 }" placeholder="S1 意图解析 Prompt" />
            </el-form-item>
            <el-form-item label="S2 任务规划 Prompt（结构化）">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">只输出 JSON：task_type/model_hint/image_list/aspect_ratios/image_size/size/resolution/need_confirm</el-tag></div>
              <el-input v-model="form.agent_sm_s2_plan_prompt" type="textarea" :autosize="{ minRows: minRowsAgentS2, maxRows: minRowsAgentS2 }" placeholder="S2 任务规划 Prompt" />
            </el-form-item>
            <el-form-item label="S3 Prompt 构建（图片专用）">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">只输出最终 Prompt 文本，不输出 JSON</el-tag></div>
              <el-input v-model="form.agent_sm_s3_image_prompt" type="textarea" :autosize="{ minRows: minRowsAgentS3Image, maxRows: minRowsAgentS3Image }" placeholder="S3 图片 Prompt 构建 Prompt" />
            </el-form-item>
            <el-form-item label="S3 Prompt 构建（视频专用）">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">只输出最终 Prompt 文本，不输出 JSON</el-tag></div>
              <el-input v-model="form.agent_sm_s3_video_prompt" type="textarea" :autosize="{ minRows: minRowsAgentS3Video, maxRows: minRowsAgentS3Video }" placeholder="S3 视频 Prompt 构建 Prompt" />
            </el-form-item>
            <el-form-item label="S3 Prompt 构建（纯文本回答）">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">用于纯文本问答，不调用工具</el-tag></div>
              <el-input v-model="form.agent_sm_s3_text_prompt" type="textarea" :autosize="{ minRows: minRowsAgentS3Text, maxRows: minRowsAgentS3Text }" placeholder="S3 文本 Prompt" />
            </el-form-item>
            <el-form-item label="S5 结果整理 Prompt">
              <div class="help-row"><el-tag size="small" type="info" effect="plain">将工具结果整理为用户可读中文摘要，不输出链接</el-tag></div>
              <el-input v-model="form.agent_sm_s5_result_prompt" type="textarea" :autosize="{ minRows: minRowsAgentS5, maxRows: minRowsAgentS5 }" placeholder="S5 结果整理 Prompt" />
            </el-form-item>
          </el-tab-pane>
          <el-tab-pane label="文章创作状态机" name="article_creation_state_machine">
            <el-form-item label="S0 对话意图解析 模型">
              <el-select v-model="form.article_sm_s0_intent_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S0 对话意图解析 System Prompt">
              <el-input v-model="form.article_sm_s0_intent_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleS0IntentSystem, maxRows: minRowsArticleS0IntentSystem }" />
            </el-form-item>
            <el-form-item label="S0b 结构先行骨架 模型">
              <el-select v-model="form.article_sm_skeleton_outline_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S0b 结构先行骨架 System Prompt">
              <el-input v-model="form.article_sm_skeleton_outline_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleSkeletonOutlineSystem, maxRows: minRowsArticleSkeletonOutlineSystem }" />
            </el-form-item>
            <el-form-item label="S0b 结构先行骨架 User Prompt">
              <el-input v-model="form.article_sm_skeleton_outline_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleSkeletonOutlineUser, maxRows: minRowsArticleSkeletonOutlineUser }" />
            </el-form-item>
            <el-form-item label="S1 资料评估 模型">
              <el-select v-model="form.article_sm_need_assess_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S1 资料评估 System Prompt">
              <el-input v-model="form.article_sm_need_assess_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleNeedAssessSystem, maxRows: minRowsArticleNeedAssessSystem }" />
            </el-form-item>
            <el-form-item label="S1 资料评估 User Prompt">
              <el-input v-model="form.article_sm_need_assess_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleNeedAssessUser, maxRows: minRowsArticleNeedAssessUser }" />
            </el-form-item>
            <el-form-item label="S2 检索计划 模型">
              <el-select v-model="form.article_sm_query_plan_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S2 检索计划 System Prompt">
              <el-input v-model="form.article_sm_query_plan_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleQueryPlanSystem, maxRows: minRowsArticleQueryPlanSystem }" />
            </el-form-item>
            <el-form-item label="S2 检索计划 User Prompt">
              <el-input v-model="form.article_sm_query_plan_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleQueryPlanUser, maxRows: minRowsArticleQueryPlanUser }" />
            </el-form-item>
            <el-form-item label="S3 事实包提炼 模型">
              <el-select v-model="form.article_sm_fact_pack_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S3 事实包提炼 System Prompt">
              <el-input v-model="form.article_sm_fact_pack_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleFactPackSystem, maxRows: minRowsArticleFactPackSystem }" />
            </el-form-item>
            <el-form-item label="S3 事实包提炼 User Prompt">
              <el-input v-model="form.article_sm_fact_pack_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleFactPackUser, maxRows: minRowsArticleFactPackUser }" />
            </el-form-item>
            <el-form-item label="S4 大纲生成 模型">
              <el-select v-model="form.article_sm_outline_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S4 大纲生成 System Prompt">
              <el-input v-model="form.article_sm_outline_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleOutlineSystem, maxRows: minRowsArticleOutlineSystem }" />
            </el-form-item>
            <el-form-item label="S4 大纲生成 User Prompt">
              <el-input v-model="form.article_sm_outline_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleOutlineUser, maxRows: minRowsArticleOutlineUser }" />
            </el-form-item>
            <el-form-item label="S5 中性稿生成 模型">
              <el-select v-model="form.article_sm_neutral_draft_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S5 中性稿生成 System Prompt">
              <el-input v-model="form.article_sm_neutral_draft_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleNeutralDraftSystem, maxRows: minRowsArticleNeutralDraftSystem }" />
            </el-form-item>
            <el-form-item label="S5 中性稿生成 User Prompt">
              <el-input v-model="form.article_sm_neutral_draft_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleNeutralDraftUser, maxRows: minRowsArticleNeutralDraftUser }" />
            </el-form-item>
            <el-form-item label="S5b 中性稿二次修订 启用开关">
              <el-switch
                v-model="form.article_sm_neutral_revision_enabled"
                :active-value="1"
                :inactive-value="0"
                active-text="启用"
                inactive-text="禁用"
              />
            </el-form-item>
            <el-form-item label="S5b 中性稿二次修订 模型">
              <el-select v-model="form.article_sm_neutral_revision_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S5b 中性稿二次修订 System Prompt">
              <el-input v-model="form.article_sm_neutral_revision_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleNeutralRevisionSystem, maxRows: minRowsArticleNeutralRevisionSystem }" />
            </el-form-item>
            <el-form-item label="S5b 中性稿二次修订 User Prompt">
              <el-input v-model="form.article_sm_neutral_revision_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleNeutralRevisionUser, maxRows: minRowsArticleNeutralRevisionUser }" />
            </el-form-item>
            <el-form-item label="S6 风格迁移改写 模型">
              <el-select v-model="form.article_sm_style_transfer_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S6 风格迁移改写 System Prompt">
              <el-input v-model="form.article_sm_style_transfer_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleStyleTransferSystem, maxRows: minRowsArticleStyleTransferSystem }" />
            </el-form-item>
            <el-form-item label="S6 风格迁移改写 User Prompt">
              <el-input v-model="form.article_sm_style_transfer_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleStyleTransferUser, maxRows: minRowsArticleStyleTransferUser }" />
            </el-form-item>
            <el-form-item label="S7 风格评审 模型">
              <el-select v-model="form.article_sm_style_qa_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S7 风格评审 System Prompt">
              <el-input v-model="form.article_sm_style_qa_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleStyleQaSystem, maxRows: minRowsArticleStyleQaSystem }" />
            </el-form-item>
            <el-form-item label="S7 风格评审 User Prompt">
              <el-input v-model="form.article_sm_style_qa_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleStyleQaUser, maxRows: minRowsArticleStyleQaUser }" />
            </el-form-item>
            <el-form-item label="S7b 风格问题局部重写 模型">
              <el-select v-model="form.article_sm_style_rewrite_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S7b 风格问题局部重写 System Prompt">
              <el-input v-model="form.article_sm_style_rewrite_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleStyleRewriteSystem, maxRows: minRowsArticleStyleRewriteSystem }" />
            </el-form-item>
            <el-form-item label="S7b 风格问题局部重写 User Prompt">
              <el-input v-model="form.article_sm_style_rewrite_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleStyleRewriteUser, maxRows: minRowsArticleStyleRewriteUser }" />
            </el-form-item>
            <el-form-item label="S9 风险段落重写 模型">
              <el-select v-model="form.article_sm_risk_rewrite_model_identity" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ')'"
                  :value="item.model_identity"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="S9 风险段落重写 System Prompt">
              <el-input v-model="form.article_sm_risk_rewrite_system_prompt" type="textarea" :autosize="{ minRows: minRowsArticleRiskRewriteSystem, maxRows: minRowsArticleRiskRewriteSystem }" />
            </el-form-item>
            <el-form-item label="S9 风险段落重写 User Prompt">
              <el-input v-model="form.article_sm_risk_rewrite_user_prompt" type="textarea" :autosize="{ minRows: minRowsArticleRiskRewriteUser, maxRows: minRowsArticleRiskRewriteUser }" />
            </el-form-item>
          </el-tab-pane>
        </el-tabs>
      </el-form>
      <div class="footer-actions">
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { getSystemPrompts, saveSystemPrompts } from '@/api/systemPrompts'
import { getAllModels } from '@/api/modelConfig'

const formRef = ref(null)
const saving = ref(false)
const activeTab = ref('input_hint_prompt')
const allModels = ref([])
const llmModels = computed(() => allModels.value.filter(m => m.model_type === 'llm'))

const calcRows = (t) => {
  const s = String(t || '')
  const lines = s.split('\n').length
  return lines + 5
}

const minRowsInputHint = computed(() => calcRows(form.input_hint_prompt))
const minRowsImageBuiltin = computed(() => calcRows(form.image_builtin_prompt))
const minRowsImageParamAdapt = computed(() => calcRows(form.image_model_param_adapt_prompt))
const minRowsLlmSidebar = computed(() => calcRows(form.llm_sidebar_system_prompt))
const minRowsLlmAssistant = computed(() => calcRows(form.llm_assistant_system_prompt))
const minRowsToolCallCallback = computed(() => calcRows(form.tool_call_callback_prompt))
const minRowsImageOcr = computed(() => calcRows(form.image_ocr_prompt))
const minRowsImageReverse = computed(() => calcRows(form.image_reverse_prompt))
const minRowsImageMarker = computed(() => calcRows(form.image_marker_prompt))
const minRowsPoseEdit = computed(() => calcRows(form.pose_edit_prompt))
const minRowsEraseTool = computed(() => calcRows(form.erase_tool_prompt))
const minRowsStyleProfileSystem = computed(() => calcRows(form.style_profile_system_prompt))
const minRowsStyleProfileSecondary = computed(() => calcRows(form.style_profile_secondary_modification_prompt))
const minRowsAgentS1 = computed(() => calcRows(form.agent_sm_s1_intent_prompt))
const minRowsAgentS2 = computed(() => calcRows(form.agent_sm_s2_plan_prompt))
const minRowsAgentS3Image = computed(() => calcRows(form.agent_sm_s3_image_prompt))
const minRowsAgentS3Video = computed(() => calcRows(form.agent_sm_s3_video_prompt))
const minRowsAgentS3Text = computed(() => calcRows(form.agent_sm_s3_text_prompt))
const minRowsAgentS5 = computed(() => calcRows(form.agent_sm_s5_result_prompt))
const minRowsArticleS0IntentSystem = computed(() => calcRows(form.article_sm_s0_intent_system_prompt))
const minRowsArticleSkeletonOutlineSystem = computed(() => calcRows(form.article_sm_skeleton_outline_system_prompt))
const minRowsArticleSkeletonOutlineUser = computed(() => calcRows(form.article_sm_skeleton_outline_user_prompt))
const minRowsArticleNeedAssessSystem = computed(() => calcRows(form.article_sm_need_assess_system_prompt))
const minRowsArticleNeedAssessUser = computed(() => calcRows(form.article_sm_need_assess_user_prompt))
const minRowsArticleQueryPlanSystem = computed(() => calcRows(form.article_sm_query_plan_system_prompt))
const minRowsArticleQueryPlanUser = computed(() => calcRows(form.article_sm_query_plan_user_prompt))
const minRowsArticleFactPackSystem = computed(() => calcRows(form.article_sm_fact_pack_system_prompt))
const minRowsArticleFactPackUser = computed(() => calcRows(form.article_sm_fact_pack_user_prompt))
const minRowsArticleOutlineSystem = computed(() => calcRows(form.article_sm_outline_system_prompt))
const minRowsArticleOutlineUser = computed(() => calcRows(form.article_sm_outline_user_prompt))
const minRowsArticleNeutralDraftSystem = computed(() => calcRows(form.article_sm_neutral_draft_system_prompt))
const minRowsArticleNeutralDraftUser = computed(() => calcRows(form.article_sm_neutral_draft_user_prompt))
const minRowsArticleNeutralRevisionSystem = computed(() => calcRows(form.article_sm_neutral_revision_system_prompt))
const minRowsArticleNeutralRevisionUser = computed(() => calcRows(form.article_sm_neutral_revision_user_prompt))
const minRowsArticleStyleTransferSystem = computed(() => calcRows(form.article_sm_style_transfer_system_prompt))
const minRowsArticleStyleTransferUser = computed(() => calcRows(form.article_sm_style_transfer_user_prompt))
const minRowsArticleStyleQaSystem = computed(() => calcRows(form.article_sm_style_qa_system_prompt))
const minRowsArticleStyleQaUser = computed(() => calcRows(form.article_sm_style_qa_user_prompt))
const minRowsArticleStyleRewriteSystem = computed(() => calcRows(form.article_sm_style_rewrite_system_prompt))
const minRowsArticleStyleRewriteUser = computed(() => calcRows(form.article_sm_style_rewrite_user_prompt))
const minRowsArticleRiskRewriteSystem = computed(() => calcRows(form.article_sm_risk_rewrite_system_prompt))
const minRowsArticleRiskRewriteUser = computed(() => calcRows(form.article_sm_risk_rewrite_user_prompt))

const form = reactive({
  input_hint_prompt: '',
  image_builtin_prompt: '',
  image_model_param_adapt_prompt: '',
  llm_sidebar_system_prompt: '',
  llm_assistant_system_prompt: '',
  tool_call_callback_prompt: '',
  image_ocr_prompt: '',
  image_reverse_prompt: '',
  image_marker_prompt: '',
  pose_edit_prompt: '',
  erase_tool_prompt: '',
  style_profile_system_prompt: '',
  style_profile_secondary_modification_prompt: '',
  agent_sm_s1_intent_prompt: '',
  agent_sm_s2_plan_prompt: '',
  agent_sm_s3_image_prompt: '',
  agent_sm_s3_video_prompt: '',
  agent_sm_s3_text_prompt: '',
  agent_sm_s5_result_prompt: '',
  article_sm_s0_intent_model_identity: '',
  article_sm_s0_intent_system_prompt: '',
  article_sm_skeleton_outline_model_identity: '',
  article_sm_skeleton_outline_system_prompt: '',
  article_sm_skeleton_outline_user_prompt: '',
  article_sm_need_assess_model_identity: '',
  article_sm_need_assess_system_prompt: '',
  article_sm_need_assess_user_prompt: '',
  article_sm_query_plan_model_identity: '',
  article_sm_query_plan_system_prompt: '',
  article_sm_query_plan_user_prompt: '',
  article_sm_fact_pack_model_identity: '',
  article_sm_fact_pack_system_prompt: '',
  article_sm_fact_pack_user_prompt: '',
  article_sm_outline_model_identity: '',
  article_sm_outline_system_prompt: '',
  article_sm_outline_user_prompt: '',
  article_sm_neutral_draft_model_identity: '',
  article_sm_neutral_draft_system_prompt: '',
  article_sm_neutral_draft_user_prompt: '',
  article_sm_neutral_revision_enabled: 0,
  article_sm_neutral_revision_model_identity: '',
  article_sm_neutral_revision_system_prompt: '',
  article_sm_neutral_revision_user_prompt: '',
  article_sm_style_transfer_model_identity: '',
  article_sm_style_transfer_system_prompt: '',
  article_sm_style_transfer_user_prompt: '',
  article_sm_style_qa_model_identity: '',
  article_sm_style_qa_system_prompt: '',
  article_sm_style_qa_user_prompt: '',
  article_sm_style_rewrite_model_identity: '',
  article_sm_style_rewrite_system_prompt: '',
  article_sm_style_rewrite_user_prompt: '',
  article_sm_risk_rewrite_model_identity: '',
  article_sm_risk_rewrite_system_prompt: '',
  article_sm_risk_rewrite_user_prompt: ''
})

const fetchAllModels = async () => {
  try {
    const res = await getAllModels()
    allModels.value = res.data || []
  } catch (e) {
    allModels.value = []
  }
}

const fetchData = async () => {
  try {
    const res = await getSystemPrompts()
    const data = res.data || {}
    form.input_hint_prompt = data.input_hint_prompt || ''
    form.image_builtin_prompt = data.image_builtin_prompt || ''
    form.image_model_param_adapt_prompt = data.image_model_param_adapt_prompt || ''
    form.llm_sidebar_system_prompt = data.llm_sidebar_system_prompt || ''
    form.llm_assistant_system_prompt = data.llm_assistant_system_prompt || ''
    form.tool_call_callback_prompt = data.tool_call_callback_prompt || ''
    form.image_ocr_prompt = data.image_ocr_prompt || ''
    form.image_reverse_prompt = data.image_reverse_prompt || ''
    form.image_marker_prompt = data.image_marker_prompt || ''
    form.pose_edit_prompt = data.pose_edit_prompt || ''
    form.erase_tool_prompt = data.erase_tool_prompt || ''
    form.style_profile_system_prompt = data.style_profile_system_prompt || ''
    form.style_profile_secondary_modification_prompt = data.style_profile_secondary_modification_prompt || ''
    form.agent_sm_s1_intent_prompt = data.agent_sm_s1_intent_prompt || ''
    form.agent_sm_s2_plan_prompt = data.agent_sm_s2_plan_prompt || ''
    form.agent_sm_s3_image_prompt = data.agent_sm_s3_image_prompt || ''
    form.agent_sm_s3_video_prompt = data.agent_sm_s3_video_prompt || ''
    form.agent_sm_s3_text_prompt = data.agent_sm_s3_text_prompt || ''
    form.agent_sm_s5_result_prompt = data.agent_sm_s5_result_prompt || ''
    form.article_sm_s0_intent_model_identity = data.article_sm_s0_intent_model_identity || ''
    form.article_sm_s0_intent_system_prompt = data.article_sm_s0_intent_system_prompt || ''
    form.article_sm_skeleton_outline_model_identity = data.article_sm_skeleton_outline_model_identity || ''
    form.article_sm_skeleton_outline_system_prompt = data.article_sm_skeleton_outline_system_prompt || ''
    form.article_sm_skeleton_outline_user_prompt = data.article_sm_skeleton_outline_user_prompt || ''
    form.article_sm_need_assess_model_identity = data.article_sm_need_assess_model_identity || ''
    form.article_sm_need_assess_system_prompt = data.article_sm_need_assess_system_prompt || ''
    form.article_sm_need_assess_user_prompt = data.article_sm_need_assess_user_prompt || ''
    form.article_sm_query_plan_model_identity = data.article_sm_query_plan_model_identity || ''
    form.article_sm_query_plan_system_prompt = data.article_sm_query_plan_system_prompt || ''
    form.article_sm_query_plan_user_prompt = data.article_sm_query_plan_user_prompt || ''
    form.article_sm_fact_pack_model_identity = data.article_sm_fact_pack_model_identity || ''
    form.article_sm_fact_pack_system_prompt = data.article_sm_fact_pack_system_prompt || ''
    form.article_sm_fact_pack_user_prompt = data.article_sm_fact_pack_user_prompt || ''
    form.article_sm_outline_model_identity = data.article_sm_outline_model_identity || ''
    form.article_sm_outline_system_prompt = data.article_sm_outline_system_prompt || ''
    form.article_sm_outline_user_prompt = data.article_sm_outline_user_prompt || ''
    form.article_sm_neutral_draft_model_identity = data.article_sm_neutral_draft_model_identity || ''
    form.article_sm_neutral_draft_system_prompt = data.article_sm_neutral_draft_system_prompt || ''
    form.article_sm_neutral_draft_user_prompt = data.article_sm_neutral_draft_user_prompt || ''
    form.article_sm_neutral_revision_enabled = Number(data.article_sm_neutral_revision_enabled ?? 0) ? 1 : 0
    form.article_sm_neutral_revision_model_identity = data.article_sm_neutral_revision_model_identity || ''
    form.article_sm_neutral_revision_system_prompt = data.article_sm_neutral_revision_system_prompt || ''
    form.article_sm_neutral_revision_user_prompt = data.article_sm_neutral_revision_user_prompt || ''
    form.article_sm_style_transfer_model_identity = data.article_sm_style_transfer_model_identity || ''
    form.article_sm_style_transfer_system_prompt = data.article_sm_style_transfer_system_prompt || ''
    form.article_sm_style_transfer_user_prompt = data.article_sm_style_transfer_user_prompt || ''
    form.article_sm_style_qa_model_identity = data.article_sm_style_qa_model_identity || ''
    form.article_sm_style_qa_system_prompt = data.article_sm_style_qa_system_prompt || ''
    form.article_sm_style_qa_user_prompt = data.article_sm_style_qa_user_prompt || ''
    form.article_sm_style_rewrite_model_identity = data.article_sm_style_rewrite_model_identity || ''
    form.article_sm_style_rewrite_system_prompt = data.article_sm_style_rewrite_system_prompt || ''
    form.article_sm_style_rewrite_user_prompt = data.article_sm_style_rewrite_user_prompt || ''
    form.article_sm_risk_rewrite_model_identity = data.article_sm_risk_rewrite_model_identity || ''
    form.article_sm_risk_rewrite_system_prompt = data.article_sm_risk_rewrite_system_prompt || ''
    form.article_sm_risk_rewrite_user_prompt = data.article_sm_risk_rewrite_user_prompt || ''
  } catch (e) {
    ElMessage.error(e?.response?.data?.msg || e?.message || '加载失败')
  }
}

onMounted(async () => {
  await fetchAllModels()
  await fetchData()
})

const handleSave = async () => {
  saving.value = true
  try {
    await saveSystemPrompts({
      input_hint_prompt: form.input_hint_prompt,
      image_builtin_prompt: form.image_builtin_prompt,
      image_model_param_adapt_prompt: form.image_model_param_adapt_prompt,
      llm_sidebar_system_prompt: form.llm_sidebar_system_prompt,
      llm_assistant_system_prompt: form.llm_assistant_system_prompt,
      tool_call_callback_prompt: form.tool_call_callback_prompt,
      image_ocr_prompt: form.image_ocr_prompt,
      image_reverse_prompt: form.image_reverse_prompt,
      image_marker_prompt: form.image_marker_prompt,
      pose_edit_prompt: form.pose_edit_prompt,
      erase_tool_prompt: form.erase_tool_prompt,
      style_profile_system_prompt: form.style_profile_system_prompt,
      style_profile_secondary_modification_prompt: form.style_profile_secondary_modification_prompt,
      agent_sm_s1_intent_prompt: form.agent_sm_s1_intent_prompt,
      agent_sm_s2_plan_prompt: form.agent_sm_s2_plan_prompt,
      agent_sm_s3_image_prompt: form.agent_sm_s3_image_prompt,
      agent_sm_s3_video_prompt: form.agent_sm_s3_video_prompt,
      agent_sm_s3_text_prompt: form.agent_sm_s3_text_prompt,
      agent_sm_s5_result_prompt: form.agent_sm_s5_result_prompt,
      article_sm_s0_intent_model_identity: form.article_sm_s0_intent_model_identity,
      article_sm_s0_intent_system_prompt: form.article_sm_s0_intent_system_prompt,
      article_sm_skeleton_outline_model_identity: form.article_sm_skeleton_outline_model_identity,
      article_sm_skeleton_outline_system_prompt: form.article_sm_skeleton_outline_system_prompt,
      article_sm_skeleton_outline_user_prompt: form.article_sm_skeleton_outline_user_prompt,
      article_sm_need_assess_model_identity: form.article_sm_need_assess_model_identity,
      article_sm_need_assess_system_prompt: form.article_sm_need_assess_system_prompt,
      article_sm_need_assess_user_prompt: form.article_sm_need_assess_user_prompt,
      article_sm_query_plan_model_identity: form.article_sm_query_plan_model_identity,
      article_sm_query_plan_system_prompt: form.article_sm_query_plan_system_prompt,
      article_sm_query_plan_user_prompt: form.article_sm_query_plan_user_prompt,
      article_sm_fact_pack_model_identity: form.article_sm_fact_pack_model_identity,
      article_sm_fact_pack_system_prompt: form.article_sm_fact_pack_system_prompt,
      article_sm_fact_pack_user_prompt: form.article_sm_fact_pack_user_prompt,
      article_sm_outline_model_identity: form.article_sm_outline_model_identity,
      article_sm_outline_system_prompt: form.article_sm_outline_system_prompt,
      article_sm_outline_user_prompt: form.article_sm_outline_user_prompt,
      article_sm_neutral_draft_model_identity: form.article_sm_neutral_draft_model_identity,
      article_sm_neutral_draft_system_prompt: form.article_sm_neutral_draft_system_prompt,
      article_sm_neutral_draft_user_prompt: form.article_sm_neutral_draft_user_prompt,
      article_sm_neutral_revision_enabled: form.article_sm_neutral_revision_enabled,
      article_sm_neutral_revision_model_identity: form.article_sm_neutral_revision_model_identity,
      article_sm_neutral_revision_system_prompt: form.article_sm_neutral_revision_system_prompt,
      article_sm_neutral_revision_user_prompt: form.article_sm_neutral_revision_user_prompt,
      article_sm_style_transfer_model_identity: form.article_sm_style_transfer_model_identity,
      article_sm_style_transfer_system_prompt: form.article_sm_style_transfer_system_prompt,
      article_sm_style_transfer_user_prompt: form.article_sm_style_transfer_user_prompt,
      article_sm_style_qa_model_identity: form.article_sm_style_qa_model_identity,
      article_sm_style_qa_system_prompt: form.article_sm_style_qa_system_prompt,
      article_sm_style_qa_user_prompt: form.article_sm_style_qa_user_prompt,
      article_sm_style_rewrite_model_identity: form.article_sm_style_rewrite_model_identity,
      article_sm_style_rewrite_system_prompt: form.article_sm_style_rewrite_system_prompt,
      article_sm_style_rewrite_user_prompt: form.article_sm_style_rewrite_user_prompt,
      article_sm_risk_rewrite_model_identity: form.article_sm_risk_rewrite_model_identity,
      article_sm_risk_rewrite_system_prompt: form.article_sm_risk_rewrite_system_prompt,
      article_sm_risk_rewrite_user_prompt: form.article_sm_risk_rewrite_user_prompt
    })
    await fetchData()
    ElMessage.success('保存成功')
  } catch (e) {
    ElMessage.error(e?.response?.data?.msg || e?.message || '保存失败')
  } finally {
    saving.value = false
  }
}
</script>

<style scoped lang="scss">
.system-prompts {
  .form-card { border: none; width: 100%; }
  .prompts-form { width: 100%; }
  .prompts-tabs { width: 100%; }
  .help-row { margin-bottom: 8px; }
  .footer-actions { margin-top: 12px; }
}
</style>
