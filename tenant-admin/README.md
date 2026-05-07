# AI SaaS Platform

这是一个基于 Vue 3 + Element Plus 的前端管理系统项目。

## 项目结构

```
src/
├── assets/          # 静态资源
├── components/      # 公共组件
├── layout/          # 布局组件
│   └── MainLayout.vue   # 主布局（侧边栏 + 顶部导航）
├── router/          # 路由配置
├── stores/          # Pinia 状态管理
├── styles/          # 全局样式
├── views/           # 页面视图
│   ├── team/        # 团队管理相关页面
│   │   └── PermissionConfig.vue  # 权限配置页面（核心复现页面）
│   ├── FinanceStats.vue    # 财务统计
│   ├── LoginView.vue       # 登录页面
│   ├── UserManagement.vue  # 用户管理
│   └── WorksManagement.vue # 作品管理
├── App.vue          # 根组件
└── main.js          # 入口文件
```

## 功能模块

1. **团队管理**
   - 权限配置：复现了参考图片中的权限分配界面，包含用户组切换和详细的权限勾选表格。
   - 菜单导航：完整的左侧导航菜单。

2. **业务管理**
   - 用户管理：用户列表、状态管理、会员等级展示。
   - 作品管理：用户生成作品的网格展示。
   - 财务统计：使用 ECharts 展示收入趋势和消费分布。

## 技术栈

- **Vue 3**: 组合式 API (Composition API)
- **Vite**: 构建工具
- **Element Plus**: UI 组件库
- **Pinia**: 状态管理
- **Vue Router**: 路由管理
- **Sass**: CSS 预处理器
- **ECharts**: 图表展示

## 运行项目

### 1. 安装依赖

```bash
npm install
```

### 2. 启动开发服务器

```bash
npm run dev
```

### 3. 代码检查

```bash
npm run lint
```

## 环境配置

本项目使用 Vite 默认配置。如需修改端口或代理，请编辑 `vite.config.js`。

```javascript
// vite.config.js
export default defineConfig({
  plugins: [vue(), vueDevTools()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  }
})
```
