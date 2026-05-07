<template>
  <div class="model-config">
    <div class="page-header" v-if="activeTab === 'list'">
      <div class="header-actions" v-if="activeTab === 'list'">
        <el-button type="primary" @click="openAddDialog">
          <el-icon><Plus /></el-icon> 新增模型
        </el-button>
      </div>
    </div>

    <el-tabs v-model="activeTab" class="demo-tabs">
      <el-tab-pane label="模型列表" name="list">
        <el-card class="table-card" shadow="never">
          <el-table :data="tableData" style="width: 100%" v-loading="loading">
            <el-table-column prop="name" label="显示名称" min-width="150" />
            <el-table-column prop="model_id" label="模型ID" min-width="150">
              <template #default="{ row }">
                <el-tag size="small" type="info">{{ row.model_id || '-' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="model_type" label="模型类型" width="120">
              <template #default="{ row }">
                <el-tag size="small" :type="getModelTypeTag(row.model_type)">
                  {{ getModelTypeName(row.model_type) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="model_identity" label="模型标识" min-width="120">
               <template #default="{ row }">
                {{ row.model_identity }}
              </template>
            </el-table-column>
            <el-table-column prop="provider_code" label="Provider" min-width="120" show-overflow-tooltip />
            <el-table-column prop="remark" label="备注" min-width="150" show-overflow-tooltip />
            <el-table-column prop="cost_per_request" label="单次消耗" min-width="100" />
            <el-table-column prop="call_count" label="累计调用数" min-width="120" />
            <el-table-column prop="status" label="状态" width="100">
              <template #default="{ row }">
                <el-switch
                  v-model="row.status"
                  active-value="active"
                  inactive-value="inactive"
                  @change="handleStatusChange(row)"
                />
              </template>
            </el-table-column>
            <el-table-column label="操作" width="180" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" @click="openEditDialog(row)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
          
          <div class="pagination-container" v-if="total > 0">
            <el-pagination
              v-model:current-page="currentPage"
              v-model:page-size="pageSize"
              :page-sizes="[10, 20, 30, 50, 100]"
              layout="total, sizes, prev, pager, next, jumper"
              :total="total"
              @size-change="handleSizeChange"
              @current-change="handleCurrentChange"
            />
          </div>
        </el-card>
      </el-tab-pane>
      
      <el-tab-pane label="默认模型配置" name="defaults">
        <el-card shadow="never" class="default-config-card">
          <el-form :model="defaultConfig" label-width="160px" style="max-width: 800px">
            <el-form-item label="默认LLM模型">
              <el-select v-model="defaultConfig.default_llm_model" placeholder="请选择默认LLM模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
              <div class="form-tip">用于系统通用的LLM对话场景</div>
            </el-form-item>

            <el-form-item label="通用创作默认模型">
              <el-select v-model="defaultConfig.default_general_llm_model" placeholder="请选择通用创作默认模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
              <div class="form-tip">用于通用创作Agent的默认模型</div>
            </el-form-item>

            <el-form-item label="图文创作默认模型">
              <el-select v-model="defaultConfig.default_article_llm_model" placeholder="请选择图文创作默认模型" style="width: 100%" clearable>
                <el-option
                  v-for="item in llmModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
              <div class="form-tip">用于文章/图文创作Agent的默认模型</div>
            </el-form-item>

            <el-form-item label="图片高清化模型">
              <el-select v-model="defaultConfig.upscale_model" placeholder="请选择图片高清化模型" style="width: 100%" clearable>
                 <el-option
                  v-for="item in imageModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
            </el-form-item>

            <el-form-item label="图片文字提取模型">
              <el-select v-model="defaultConfig.ocr_model" placeholder="请选择图片文字提取模型" style="width: 100%" clearable>
                 <el-option
                  v-for="item in visionModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
            </el-form-item>

            <el-form-item label="图片文字修改模型">
              <el-select v-model="defaultConfig.text_edit_model" placeholder="请选择图片文字修改模型" style="width: 100%" clearable>
                 <el-option
                  v-for="item in imageModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
            </el-form-item>

            <el-form-item label="人物动作修改模型">
              <el-select v-model="defaultConfig.pose_edit_model" placeholder="请选择人物动作修改模型" style="width: 100%" clearable>
                 <el-option
                  v-for="item in imageModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_identity + ' / ' + item.model_id + ')'"
                  :value="item.model_identity"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
            </el-form-item>

            <el-form-item label="图片背景去除模型">
              <el-select v-model="defaultConfig.remove_bg_model" placeholder="请选择图片背景去除模型" style="width: 100%" clearable>
                 <el-option
                  v-for="item in imagesegModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
            </el-form-item>

            <el-form-item label="逆推图片提示词模型">
              <el-select v-model="defaultConfig.reverse_prompt_model" placeholder="请选择逆推图片提示词模型" style="width: 100%" clearable>
                 <el-option
                  v-for="item in visionModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
            </el-form-item>

            <el-form-item label="图片去水印模型">
              <el-select v-model="defaultConfig.remove_watermark_model" placeholder="请选择图片去水印模型" style="width: 100%" clearable>
                 <el-option
                  v-for="item in imageModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
            </el-form-item>

            <el-form-item label="擦除工具默认模型">
              <el-select v-model="defaultConfig.erase_tool_model" placeholder="请选择擦除工具默认模型" style="width: 100%" clearable>
                 <el-option
                  v-for="item in imageModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
            </el-form-item>

            <el-form-item label="图片标注物识别模型">
              <el-select v-model="defaultConfig.object_detection_model" placeholder="请选择图片标注物识别模型" style="width: 100%" clearable>
                 <el-option
                  v-for="item in visionModels"
                  :key="item.id"
                  :label="item.name + ' (' + item.model_id + ')'"
                  :value="item.model_id"
                >
                  <span style="float: left">{{ item.name }}</span>
                  <span style="float: right; color: #8492a6; font-size: 13px">{{ getModelTypeName(item.model_type) }}</span>
                </el-option>
              </el-select>
            </el-form-item>

            <el-form-item>
              <el-button type="primary" @click="saveDefaultConfig" :loading="savingDefaults">保存配置</el-button>
            </el-form-item>
          </el-form>
        </el-card>
      </el-tab-pane>

      <el-tab-pane label="Web Search配置" name="web_search">
        <el-card shadow="never" class="default-config-card">
          <el-form :model="webSearchConfig" label-width="160px" style="max-width: 800px">
            <el-form-item label="默认搜索方法">
              <el-radio-group v-model="webSearchConfig.search_provider_toggle">
                <el-radio-button label="zhipu">智谱</el-radio-button>
                <el-radio-button label="searxng">SearXNG</el-radio-button>
                <el-radio-button label="volcengine">火山引擎</el-radio-button>
              </el-radio-group>
            </el-form-item>

            <el-form-item label="SearXNG 接口地址" v-if="webSearchConfig.search_provider_toggle === 'searxng'">
              <el-input v-model="webSearchConfig.searxng_endpoint" placeholder="例如：https://your-searxng.com/search" />
            </el-form-item>

            <el-form-item label="SearXNG API Key" v-if="webSearchConfig.search_provider_toggle === 'searxng'">
              <el-input v-model="webSearchConfig.searxng_api_key" type="password" show-password placeholder="可选" />
            </el-form-item>

            <el-form-item label="SearXNG Engines" v-if="webSearchConfig.search_provider_toggle === 'searxng'">
              <el-input v-model="webSearchConfig.searxng_engines" placeholder="例如：bing,360search,sogou" />
              <div class="form-tip">可选；为空则使用 SearXNG 默认引擎</div>
            </el-form-item>

            <el-form-item label="SearXNG 结果条数" v-if="webSearchConfig.search_provider_toggle === 'searxng'">
              <el-input-number v-model="webSearchConfig.searxng_result_count" :min="1" :max="50" style="width: 100%" />
            </el-form-item>

            <el-form-item label="智谱 API Key" v-if="webSearchConfig.search_provider_toggle === 'zhipu'">
              <el-input v-model="webSearchConfig.api_key" type="password" show-password placeholder="id.secret 或 sk-..." />
              <div class="form-tip">用于调用智谱 Web Search API</div>
            </el-form-item>

            <el-form-item label="接口地址" v-if="webSearchConfig.search_provider_toggle === 'zhipu'">
              <el-input v-model="webSearchConfig.endpoint" placeholder="默认：https://open.bigmodel.cn/api/paas/v4/web_search" />
            </el-form-item>

            <el-form-item label="搜索引擎" v-if="webSearchConfig.search_provider_toggle === 'zhipu'">
              <el-select v-model="webSearchConfig.search_engine" style="width: 100%">
                <el-option label="search_std（智谱基础）" value="search_std" />
                <el-option label="search_pro（智谱高阶）" value="search_pro" />
                <el-option label="search_pro_sogou（搜狗）" value="search_pro_sogou" />
                <el-option label="search_pro_quark（夸克）" value="search_pro_quark" />
              </el-select>
            </el-form-item>

            <el-form-item label="结果条数" v-if="webSearchConfig.search_provider_toggle === 'zhipu'">
              <el-input-number v-model="webSearchConfig.count" :min="1" :max="50" style="width: 100%" />
            </el-form-item>

            <el-form-item label="时间范围" v-if="webSearchConfig.search_provider_toggle === 'zhipu'">
              <el-select v-model="webSearchConfig.search_recency_filter" style="width: 100%">
                <el-option label="不限" value="noLimit" />
                <el-option label="一天内" value="oneDay" />
                <el-option label="一周内" value="oneWeek" />
                <el-option label="一月内" value="oneMonth" />
                <el-option label="一年内" value="oneYear" />
              </el-select>
            </el-form-item>

            <el-form-item label="摘要长度" v-if="webSearchConfig.search_provider_toggle === 'zhipu'">
              <el-select v-model="webSearchConfig.content_size" style="width: 100%">
                <el-option label="medium（常规摘要）" value="medium" />
                <el-option label="high（更长摘要）" value="high" />
              </el-select>
            </el-form-item>

            <el-form-item label="域名白名单" v-if="webSearchConfig.search_provider_toggle === 'zhipu'">
              <el-input v-model="webSearchConfig.search_domain_filter" placeholder="可选，如：www.sohu.com" />
            </el-form-item>

            <el-form-item label="搜索意图识别" v-if="webSearchConfig.search_provider_toggle === 'zhipu'">
              <el-switch v-model="webSearchConfig.search_intent" />
            </el-form-item>

            <el-form-item label="火山引擎 API Key" v-if="webSearchConfig.search_provider_toggle === 'volcengine'">
              <el-input v-model="webSearchConfig.volc_api_key" type="password" show-password placeholder="ARK_API_KEY" />
            </el-form-item>

            <el-form-item label="火山引擎接口地址" v-if="webSearchConfig.search_provider_toggle === 'volcengine'">
              <el-input v-model="webSearchConfig.volc_endpoint" placeholder="默认：https://ark.cn-beijing.volces.com/api/v3/responses" />
            </el-form-item>

            <el-form-item label="火山引擎 Model" v-if="webSearchConfig.search_provider_toggle === 'volcengine'">
              <el-input v-model="webSearchConfig.volc_model" placeholder="例如：doubao-seed-1-6-250615 或 endpoint-id" />
            </el-form-item>

            <el-form-item>
              <el-button type="primary" @click="saveWebSearchConfig" :loading="savingWebSearch">保存配置</el-button>
            </el-form-item>
          </el-form>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- Add/Edit Model Dialog -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEditing ? '编辑模型' : '新增模型'"
      width="500px"
    >
      <el-form :model="form" label-width="100px" ref="formRef" :rules="rules">
        <el-form-item label="显示名称" prop="name">
          <el-input v-model="form.name" placeholder="例如：Stable Diffusion XL" />
        </el-form-item>
        <el-form-item label="模型ID" prop="model_id">
          <el-input v-model="form.model_id" placeholder="API调用时的模型名称，如：gpt-4" />
        </el-form-item>
        <el-form-item label="模型标识" prop="model_identity">
          <el-input v-model="form.model_identity" placeholder="请输入模型标识，如：openai" />
        </el-form-item>
        <el-form-item label="Provider" prop="provider_code">
          <el-select
            v-model="form.provider_code"
            filterable
            clearable
            allow-create
            default-first-option
            placeholder="用于路由，如：seedream / openai_image（留空则按模型标识）"
            style="width: 100%"
          >
            <el-option-group label="生图/图像">
              <el-option v-for="it in providerOptions.image" :key="it" :label="it" :value="it" />
            </el-option-group>
            <el-option-group label="LLM">
              <el-option v-for="it in providerOptions.llm" :key="it" :label="it" :value="it" />
            </el-option-group>
            <el-option-group label="视频">
              <el-option v-for="it in providerOptions.video" :key="it" :label="it" :value="it" />
            </el-option-group>
          </el-select>
        </el-form-item>
        <el-form-item label="模型类型" prop="model_type">
          <el-select v-model="form.model_type" placeholder="请选择模型类型" style="width: 100%">
            <el-option label="生图模型" value="image" />
            <el-option label="视频模型" value="video" />
            <el-option label="大语言模型" value="llm" />
            <el-option label="音频模型" value="audio" />
            <el-option label="视觉理解" value="vision" />
            <el-option label="阿里云通用抠图" value="imageseg" />
            <el-option label="阿里云高清抠图" value="imageseg_hd" />
          </el-select>
        </el-form-item>
        <el-form-item label="单次消耗" prop="cost_per_request">
          <el-input-number v-model="form.cost_per_request" :min="0" :step="1" style="width: 100%" />
        </el-form-item>
        <el-form-item label="累计调用数" prop="call_count">
          <el-input-number v-model="form.call_count" :min="0" :step="1" style="width: 100%" placeholder="可手动修改以重置" />
        </el-form-item>
        <el-form-item label="API Key" prop="api_key">
          <el-input v-model="form.api_key" type="password" show-password placeholder="sk-..." />
        </el-form-item>
        <el-form-item label="API地址" prop="endpoint">
          <el-input v-model="form.endpoint" placeholder="可选，默认使用官方地址" />
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="form.remark" type="textarea" :rows="2" placeholder="请输入备注信息（选填）" />
        </el-form-item>

        <template v-if="form.model_type === 'image'">
          <el-divider content-position="left">参数价格配置</el-divider>
          <el-form-item label="分辨率配置">
            <div class="config-list">
              <div v-for="(item, index) in form.resolution_config" :key="index" class="config-item">
                <el-input v-model="item.value" placeholder="例如：1024x1024" size="small" style="width: 160px" />
                <el-input-number v-model="item.price" :min="0" size="small" style="width: 100px" placeholder="积分" />
                <el-button type="danger" link @click="removeConfigItem('resolution_config', index)">
                  <el-icon><Delete /></el-icon>
                </el-button>
              </div>
              <el-button type="primary" link size="small" @click="addConfigItem('resolution_config')">
                <el-icon><Plus /></el-icon> 添加分辨率
              </el-button>
            </div>
          </el-form-item>
        </template>

        <template v-if="form.model_type === 'video'">
          <el-divider content-position="left">功能配置</el-divider>
          <el-row :gutter="20">
            <el-col :span="12">
              <el-form-item label="首帧功能">
                <el-switch v-model="form.enable_first_frame" :active-value="1" :inactive-value="0" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="首尾帧功能">
                <el-switch v-model="form.enable_first_last_frame" :active-value="1" :inactive-value="0" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="多图参考">
                <el-switch v-model="form.enable_multi_image_ref" :active-value="1" :inactive-value="0" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="视频参考">
                <el-switch v-model="form.enable_video_ref" :active-value="1" :inactive-value="0" />
              </el-form-item>
            </el-col>
          </el-row>
          
          <el-divider content-position="left">参数价格配置</el-divider>
          
          <el-form-item label="尺寸配置">
            <div class="config-list">
              <div v-for="(item, index) in form.size_config" :key="index" class="config-item">
                <el-input v-model="item.value" placeholder="例如：1024x1024" size="small" style="width: 160px" />
                <el-input-number v-model="item.price" :min="0" size="small" style="width: 100px" placeholder="积分" />
                <el-button type="danger" link @click="removeConfigItem('size_config', index)">
                  <el-icon><Delete /></el-icon>
                </el-button>
              </div>
              <el-button type="primary" link size="small" @click="addConfigItem('size_config')">
                <el-icon><Plus /></el-icon> 添加尺寸
              </el-button>
            </div>
          </el-form-item>

          <el-form-item label="时长配置">
            <div class="config-list">
              <div v-for="(item, index) in form.duration_config" :key="index" class="config-item">
                <el-input v-model="item.value" placeholder="例如：5s" size="small" style="width: 160px" />
                <el-input-number v-model="item.price" :min="0" size="small" style="width: 100px" placeholder="积分" />
                <el-button type="danger" link @click="removeConfigItem('duration_config', index)">
                  <el-icon><Delete /></el-icon>
                </el-button>
              </div>
              <el-button type="primary" link size="small" @click="addConfigItem('duration_config')">
                <el-icon><Plus /></el-icon> 添加时长
              </el-button>
            </div>
          </el-form-item>

          <el-form-item label="视频比例">
            <div class="config-list">
              <div v-for="(item, index) in form.aspect_ratio_config" :key="index" class="config-item">
                <el-input v-model="item.value" placeholder="例如：16:9" size="small" style="width: 160px" />
                <el-input-number v-model="item.price" :min="0" size="small" style="width: 100px" placeholder="积分" />
                <el-button type="danger" link @click="removeConfigItem('aspect_ratio_config', index)">
                  <el-icon><Delete /></el-icon>
                </el-button>
              </div>
              <el-button type="primary" link size="small" @click="addConfigItem('aspect_ratio_config')">
                <el-icon><Plus /></el-icon> 添加比例
              </el-button>
            </div>
          </el-form-item>

          <el-form-item label="清晰度配置">
            <div class="config-list">
              <div v-for="(item, index) in form.quality_config" :key="index" class="config-item">
                <el-input v-model="item.value" placeholder="例如：HD" size="small" style="width: 160px" />
                <el-input-number v-model="item.price" :min="0" size="small" style="width: 100px" placeholder="积分" />
                <el-button type="danger" link @click="removeConfigItem('quality_config', index)">
                  <el-icon><Delete /></el-icon>
                </el-button>
              </div>
              <el-button type="primary" link size="small" @click="addConfigItem('quality_config')">
                <el-icon><Plus /></el-icon> 添加清晰度
              </el-button>
            </div>
          </el-form-item>
        </template>

        <el-form-item label="状态">
          <el-switch
            v-model="form.status"
            active-value="active"
            inactive-value="inactive"
            active-text="启用"
            inactive-text="停用"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" @click="submitForm" :loading="submitting">确认</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Delete } from '@element-plus/icons-vue'
import { getModelList, createModel, updateModel, deleteModel, updateModelStatus, getAllModels } from '@/api/modelConfig'
import { getConfig, updateConfig } from '@/api/systemConfig'

// --- Model Logic ---
const activeTab = ref('list')
const tableData = ref([])
const loading = ref(false)
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(30)
const allModels = ref([])

// Computed properties for filtered models
const llmModels = computed(() => allModels.value.filter(m => m.model_type === 'llm'))
const imageModels = computed(() => allModels.value.filter(m => m.model_type === 'image'))
const visionModels = computed(() => allModels.value.filter(m => m.model_type === 'vision'))
const imagesegModels = computed(() => allModels.value.filter(m => ['imageseg', 'imageseg_hd'].includes(m.model_type)))

// Default Config State
const savingDefaults = ref(false)
const defaultConfig = reactive({
  default_llm_model: '',
  default_general_llm_model: '',
  default_article_llm_model: '',
  upscale_model: '',
  ocr_model: '',
  text_edit_model: '',
  pose_edit_model: '',
  remove_bg_model: '',
  reverse_prompt_model: '',
  remove_watermark_model: '',
  erase_tool_model: '',
  object_detection_model: ''
})

const savingWebSearch = ref(false)
const webSearchConfig = reactive({
  api_key: '',
  endpoint: 'https://open.bigmodel.cn/api/paas/v4/web_search',
  search_engine: 'search_std',
  count: 10,
  search_domain_filter: '',
  search_recency_filter: 'noLimit',
  content_size: 'medium',
  search_intent: false,
  search_provider_toggle: 'zhipu',
  searxng_endpoint: '',
  searxng_api_key: '',
  searxng_engines: '',
  searxng_result_count: 10,
  volc_api_key: '',
  volc_endpoint: 'https://ark.cn-beijing.volces.com/api/v3/responses',
  volc_model: 'doubao-seed-1-6-250615'
})

// Dialog State
const dialogVisible = ref(false)
const isEditing = ref(false)
const submitting = ref(false)
const formRef = ref(null)
const currentId = ref(null)

const form = reactive({
  name: '',
  model_id: '',
  model_identity: '',
  provider_code: '',
  model_type: 'llm',
  cost_per_request: 1,
  call_count: 0,
  api_key: '',
  endpoint: '',
  remark: '',
  status: 'active',
  enable_first_frame: 0,
  enable_first_last_frame: 0,
  enable_multi_image_ref: 0,
  enable_video_ref: 0,
  size_config: [],
  duration_config: [],
  aspect_ratio_config: [],
  quality_config: [],
  resolution_config: []
})

const addConfigItem = (field) => {
  form[field].push({ value: '', price: 0 })
}

const removeConfigItem = (field, index) => {
  form[field].splice(index, 1)
}

const getModelTypeName = (type) => {
  const map = {
    'llm': '大语言模型',
    'image': '生图模型',
    'video': '视频模型',
    'audio': '音频模型',
    'vision': '视觉理解',
    'imageseg': '阿里云通用抠图',
    'imageseg_hd': '阿里云高清抠图'
  }
  return map[type] || type
}

const getModelTypeTag = (type) => {
  const map = {
    'llm': '',
    'image': 'success',
    'video': 'warning',
    'audio': 'info',
    'vision': 'success',
    'imageseg': 'danger',
    'imageseg_hd': 'danger'
  }
  return map[type] || ''
}

const rules = {
  name: [{ required: true, message: '请输入显示名称', trigger: 'blur' }],
  model_id: [{ required: true, message: '请输入模型ID', trigger: 'blur' }],
  model_identity: [{ required: true, message: '请输入模型标识', trigger: 'blur' }]
}

const providerOptions = {
  image: [
    'openai_image',
    'openai',
    'seedream',
    'seedream3.0',
    'seedream4.0',
    'seedream4.5',
    'seedream5.0',
    'seedream5',
    'duomi-nano-banana-pro',
    'nanobananapro',
    'banana',
    'gemini-3-pro-image-antigravity',
    'imageseg',
    'imageseg_hd',
    'imageHDseg',
    'qwen-image-edit-max',
    'qwen-image-edit-plus',
    'gpt-image-2',
    'gpt-image-2-apiyi'
  ],
  llm: [
    'doubao',
    'doubaoseed',
    'gpt5',
    'claude',
    'qwen',
    'deepseek',
    'moonshot',
    'kimi',
    'glm'
  ],
  video: [
    'sora2',
    'test-video'
  ]
}

// Fetch Data
const fetchData = async () => {
  loading.value = true
  try {
    const res = await getModelList({
      page: currentPage.value,
      limit: pageSize.value
    })
    tableData.value = res.data.data
    total.value = res.data.total
  } catch (error) {
    console.error(error)
  } finally {
    loading.value = false
  }
}

const fetchAllModels = async () => {
  try {
    const res = await getAllModels()
    allModels.value = res.data || []
  } catch (error) {
    console.error('Failed to fetch all models:', error)
  }
}

const fetchDefaultConfig = async () => {
  try {
    const res = await getConfig('default_models')
    const config = res.data || {}
    Object.keys(defaultConfig).forEach(key => {
      if (config[key]) {
        if (key === 'pose_edit_model') {
          const raw = String(config[key] ?? '')
          if (!raw) return
          const byIdentity = imageModels.value.find(m => m.model_identity === raw)
          if (byIdentity) {
            defaultConfig.pose_edit_model = byIdentity.model_identity
            return
          }
          const byModelId = imageModels.value.find(m => m.model_id === raw)
          if (byModelId) {
            defaultConfig.pose_edit_model = byModelId.model_identity
            return
          }
          defaultConfig.pose_edit_model = raw
          return
        }
        if (key === 'erase_tool_model') {
          const raw = String(config[key] ?? '')
          if (!raw) return
          const byModelId = imageModels.value.find(m => m.model_id === raw)
          if (byModelId) {
            defaultConfig.erase_tool_model = byModelId.model_id
            return
          }
          const byIdentity = imageModels.value.find(m => m.model_identity === raw)
          if (byIdentity) {
            defaultConfig.erase_tool_model = byIdentity.model_id
            return
          }
          defaultConfig.erase_tool_model = raw
          return
        }
        defaultConfig[key] = config[key]
      }
    })
  } catch (error) {
    console.error('Failed to fetch default config:', error)
  }
}

const fetchWebSearchConfig = async () => {
  try {
    const res = await getConfig('web_search')
    const config = res.data || {}
    Object.keys(webSearchConfig).forEach(key => {
      if (config[key] !== undefined && config[key] !== null) {
        webSearchConfig[key] = config[key]
      }
    })
    if (webSearchConfig.search_provider_toggle === 'current') {
      webSearchConfig.search_provider_toggle = 'zhipu'
    }
  } catch (error) {
    console.error('Failed to fetch web search config:', error)
  }
}

const saveDefaultConfig = async () => {
  savingDefaults.value = true
  try {
    await updateConfig('default_models', defaultConfig)
    ElMessage.success('默认配置保存成功')
  } catch (error) {
    console.error('Failed to save default config:', error)
    ElMessage.error('保存失败')
  } finally {
    savingDefaults.value = false
  }
}

const saveWebSearchConfig = async () => {
  savingWebSearch.value = true
  try {
    await updateConfig('web_search', webSearchConfig)
    ElMessage.success('Web Search 配置保存成功')
  } catch (error) {
    console.error('Failed to save web search config:', error)
    ElMessage.error('保存失败')
  } finally {
    savingWebSearch.value = false
  }
}

onMounted(async () => {
  await fetchData()
  await fetchAllModels()
  await fetchDefaultConfig()
  await fetchWebSearchConfig()
})

const handleSizeChange = (val) => {
  pageSize.value = val
  fetchData()
}

const handleCurrentChange = (val) => {
  currentPage.value = val
  fetchData()
}

const resetForm = () => {
  form.name = ''
  form.model_id = ''
  form.model_identity = ''
  form.provider_code = ''
  form.model_type = 'llm'
  form.cost_per_request = 1
  form.call_count = 0
  form.api_key = ''
  form.endpoint = ''
  form.remark = ''
  form.status = 'active'
  form.enable_first_frame = 0
  form.enable_first_last_frame = 0
  form.enable_multi_image_ref = 0
  form.enable_video_ref = 0
  form.size_config = []
  form.duration_config = []
  form.aspect_ratio_config = []
  form.quality_config = []
  form.resolution_config = []
  if (formRef.value) {
    formRef.value.resetFields()
  }
}

const openAddDialog = () => {
  isEditing.value = false
  currentId.value = null
  resetForm()
  dialogVisible.value = true
}

const openEditDialog = (row) => {
  isEditing.value = true
  currentId.value = row.id
  // Copy values
  form.name = row.name
  form.model_id = row.model_id
  form.model_identity = row.model_identity
  form.provider_code = row.provider_code || ''
  form.model_type = row.model_type || 'llm'
  form.cost_per_request = row.cost_per_request || 1
  form.call_count = row.call_count || 0
  form.api_key = row.api_key 
  form.endpoint = row.endpoint || ''
  form.remark = row.remark || ''
  form.status = row.status
  form.enable_first_frame = row.enable_first_frame == 1 ? 1 : 0
  form.enable_first_last_frame = row.enable_first_last_frame == 1 ? 1 : 0
  form.enable_multi_image_ref = row.enable_multi_image_ref == 1 ? 1 : 0
  form.enable_video_ref = row.enable_video_ref == 1 ? 1 : 0
  
  try {
    form.size_config = typeof row.size_config === 'string' && row.size_config ? JSON.parse(row.size_config) : (row.size_config || [])
  } catch (e) { form.size_config = [] }
  
  try {
    form.duration_config = typeof row.duration_config === 'string' && row.duration_config ? JSON.parse(row.duration_config) : (row.duration_config || [])
  } catch (e) { form.duration_config = [] }

  try {
    form.aspect_ratio_config = typeof row.aspect_ratio_config === 'string' && row.aspect_ratio_config ? JSON.parse(row.aspect_ratio_config) : (row.aspect_ratio_config || [])
  } catch (e) { form.aspect_ratio_config = [] }

  try {
    form.quality_config = typeof row.quality_config === 'string' && row.quality_config ? JSON.parse(row.quality_config) : (row.quality_config || [])
  } catch (e) { form.quality_config = [] }

  try {
    form.resolution_config = typeof row.resolution_config === 'string' && row.resolution_config ? JSON.parse(row.resolution_config) : (row.resolution_config || [])
  } catch (e) { form.resolution_config = [] }

  dialogVisible.value = true
}

const submitForm = async () => {
  if (!formRef.value) return
  
  await formRef.value.validate(async (valid) => {
    if (valid) {
      submitting.value = true
      try {
        if (isEditing.value) {
          await updateModel(currentId.value, form)
          ElMessage.success('模型更新成功')
        } else {
          await createModel(form)
          ElMessage.success('模型添加成功')
        }
        dialogVisible.value = false
        fetchData()
      } catch (error) {
        console.error(error)
      } finally {
        submitting.value = false
      }
    }
  })
}

const handleDelete = (row) => {
  ElMessageBox.confirm(
    `确定要删除模型 "${row.name}" 吗？`,
    '警告',
    {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    }
  ).then(async () => {
    try {
      await deleteModel(row.id)
      ElMessage.success('已删除')
      fetchData()
    } catch (error) {
      console.error(error)
    }
  }).catch(() => {})
}

const handleStatusChange = async (row) => {
  try {
    await updateModelStatus(row.id, row.status)
    ElMessage.success('状态已更新')
  } catch (error) {
    row.status = row.status === 'active' ? 'inactive' : 'active'
    console.error(error)
  }
}
</script>

<style scoped lang="scss">
.model-config {
  .page-header {
    display: flex;
    justify-content: flex-start;
    align-items: center;
    margin-bottom: 24px;
    
    .header-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }
  }

  .table-card {
    border: none;
  }

  .pagination-container {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
  }
  
  .config-list {
    width: 100%;
    .config-item {
      display: flex;
      gap: 10px;
      margin-bottom: 10px;
      align-items: center;
    }
  }

  .default-config-card {
    border: none;
    .form-tip {
      font-size: 12px;
      color: #909399;
      line-height: 1.5;
      margin-top: 5px;
    }
  }
}
</style>
