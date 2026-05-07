# analyse_image 工具开发文档

## 1. 工具定位
analyse_image 是“图像内容分析”工具：接收 1 张或多张图片的 URL，并结合用户的分析目标（prompt）与任务上下文（current_task_context），输出结构化的图像分析结果，用于后续的图像生成/编辑决策、信息提取与对比。

## 2. 工具功能（What）
- 多图并行分析：image_url_list 支持 1..N 张图片，返回按图片拆分的结果，并可补充跨图对比/汇总。
- 视觉元素识别：物体/人物/场景/产品、颜色、形状、纹理、构图层级等。
- 设计特征分析：配色、排版（字体/字号/字重/对齐）、布局与视觉焦点、整体风格（现代/复古/简约等）。
- 内容信息提取：图片中的文字（OCR）、图标/装饰元素、品牌标识等。
- 上下文理解：结合 current_task_context 推断用途、目标受众、核心信息。

## 3. 输入（API 形态与字段）
建议将工具输入定义为一个 JSON 对象（或等价的函数参数集合）。

### 3.1 必填字段
- prompt: string
  - 含义：分析目标与关注点（决定“分析什么、输出多细”）。
  - 编写要点：清晰、具体、结构化，避免重复与空泛。
- current_task_context: string
  - 含义：任务背景（决定“为什么分析、结果将用于什么决策/后续动作”）。
- image_url_list: string[]
  - 含义：待分析图片 URL 列表。
- current_conversation_language: string
  - 含义：当前对话语言（用于输出语言与术语风格一致）。
  - 取值示例："Chinese" / "English" / "Japanese"。

## 4. 输出（推荐结构）
建议输出稳定的结构化 JSON，方便下游消费。最少包含：每张图的分析结果 + 可选的跨图汇总。

### 4.1 单图结果（image_result）
- image_url: string
- visual_elements: object[]
  - 每个元素建议包含：type/name/position/size/color/material/features 等（允许缺省）。
- design_features: object
  - color_scheme: string[]（可选）
  - typography: object（可选，font_family/font_size/font_weight/color/align 等）
  - layout: string（可选）
  - style: string（可选）
- content_information: object
  - text: object[]（OCR 结果：content/position/语言/置信度等）
  - icons_or_graphics: object[]（可选）
- context_understanding: object
  - purpose / target_audience / design_goal（可选）
- suggestions: string[]（可选，改进点/风险点/不确定性提示）

### 4.2 多图汇总（可选）
- overall_summary: string
- comparisons: object[]（例如：差异点、共性、可复用设计元素）

## 5. 实现方法（How：可落地的工程方案）
- 参数校验：必填字段非空；URL 白名单与协议校验；坐标范围校验；列表长度与图片大小限制。
- 图片获取：服务端拉取 image_url_list；设置超时、重试与内容类型校验；必要时做缓存与去重（相同 URL/hash）。
- 视觉模型调用：使用可配置的多模态视觉大模型提供商（实现保持“模型可插拔”）。工具本身不对外暴露独立 system prompt，但内部可使用固定的“结构化输出指令模板”来稳定返回 schema。
- 并行策略：多图可并行（受并发与速率限制）；最终按输入顺序合并结果，附加每图状态。
- 结构化解析：对模型输出进行 JSON 解析/纠错（必要时二次约束/重试），保证字段稳定；不确定内容要显式标注。
- 错误处理：
  - 下载失败/非图片/超限：返回可诊断错误（包含 image_url 与原因）。
  - 模型超时/限流：可重试并返回部分成功结果。

## 6. 调用示例（请求示意）
```json
{
  "prompt": "提取图中的所有文字与排版信息，并概括整体风格与配色",
  "current_task_context": "用户要基于该海报做改版，需要先盘点文案与版式",
  "image_url_list": ["https://example.com/poster.jpg"],
  "current_conversation_language": "Chinese"
}
```

---